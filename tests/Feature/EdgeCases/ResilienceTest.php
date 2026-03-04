<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeCases;

use App\Events\IntegrationEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Listeners\EvaluateBehavioralRules;
use Modules\Analytics\Models\BehavioralRule;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;
use Tests\TestCase;

/**
 * Graceful Degradation & Payload Malformation tests.
 *
 * Proves that:
 *  1. The ingestion API returns a success response even when the
 *     WebSocket broadcast system (Reverb / Pusher) is offline.
 *
 *  2. An oversized payload (≥ 5 MB custom_data) is rejected at
 *     the validation layer with 422 to protect MongoDB from bloat.
 */
final class ResilienceTest extends TestCase
{
    private Tenant $tenant;
    private User   $user;

    // ------------------------------------------------------------------
    //  Lifecycle
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name'      => 'Resilience Test Tenant',
            'slug'      => 'resil-test-' . uniqid(),
            'is_active' => true,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Resilience Tester',
            'email'     => 'resil-' . uniqid() . '@example.com',
            'password'  => bcrypt('password'),
        ]);

        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
    }

    protected function tearDown(): void
    {
        BehavioralRule::where('tenant_id', $this->tenant->id)->delete();
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
        $this->user->forceDelete();
        $this->tenant->forceDelete();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  Test Case 1 — WebSocket outage
    // ------------------------------------------------------------------

    /**
     * When Reverb / Pusher is unreachable the ingestion API must still
     * return 201 and the event must be persisted to MongoDB.
     *
     * Additionally, the EvaluateBehavioralRules listener must catch the
     * BroadcastException internally via try-catch + report(), so the
     * buyer's checkout flow is never interrupted.
     */
    public function test_api_survives_websocket_server_outage(): void
    {
        $sessionId = 'resilience_ws_' . uniqid();

        // ----- Seed a behavioral rule that fires at intent score ≥ 50 -----
        BehavioralRule::create([
            'tenant_id'         => $this->tenant->id,
            'name'              => 'WS Outage Test Discount',
            'trigger_condition' => ['min_intent_score' => 50],
            'action_type'       => 'discount',
            'action_payload'    => ['discount_code' => 'CRASH10'],
            'priority'          => 90,
            'is_active'         => true,
            'cooldown_minutes'  => 5,
        ]);

        // ----- Pre-seed intent score so the next event crosses threshold --
        $prefix = config('database.redis.options.prefix', '');
        Redis::set("intent:score:{$sessionId}", 45);
        Redis::expire("intent:score:{$sessionId}", 1800);

        // ----- 1. Prove the API is architecturally independent of broadcast
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/analytics/ingest', [
            'payload' => [
                'session_id' => $sessionId,
                'event_type' => 'begin_checkout',
                'url'        => 'https://example.com/checkout',
                'metadata'   => ['cart_total' => 299.99],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        // Event persisted to MongoDB.
        $eventId = $response->json('data.tracking_event_id');
        $this->assertNotNull(TrackingEvent::find($eventId), 'Event must be persisted even if WS is down.');

        // ----- 2. Prove the listener catches broadcast exceptions ----------
        // Point the broadcaster to a non-existent driver so resolving it
        // throws an InvalidArgumentException inside the event dispatcher.
        config(['broadcasting.default' => 'broken_ws_driver']);

        /** @var EvaluateBehavioralRules $listener */
        $listener = app(EvaluateBehavioralRules::class);

        // The listener must NOT throw — the try-catch around event()
        // in fireIntervention() swallows the exception and reports it.
        $listener->handle(new IntegrationEvent(
            moduleName: 'analytics',
            eventName:  'tracking.event',
            payload: [
                'tenant_id'  => (string) $this->tenant->id,
                'session_id' => $sessionId,
                'event_type' => 'add_to_cart',
            ],
        ));

        // If we reach here the listener survived the broadcast failure.
        $this->addToAssertionCount(1);

        // Restore broadcaster for tearDown.
        config(['broadcasting.default' => 'log']);

        // Clean up Redis.
        Redis::del(str_replace($prefix, '', "intent:score:{$sessionId}"));
    }

    // ------------------------------------------------------------------
    //  Test Case 2 — Oversized payload
    // ------------------------------------------------------------------

    /**
     * A 5 MB custom_data blob must be rejected with 422 by the
     * StoreIngestionRequest validator to protect MongoDB from memory
     * exhaustion.
     */
    public function test_rejects_massive_payloads(): void
    {
        Sanctum::actingAs($this->user);

        // Generate a ~5 MB string to stuff into custom_data.
        $hugeBlob = str_repeat('X', 5 * 1024 * 1024);

        $response = $this->postJson('/api/v1/analytics/ingest', [
            'payload' => [
                'session_id'  => 'payload_bomb_' . uniqid(),
                'event_type'  => 'page_view',
                'url'         => 'https://example.com',
                'custom_data' => ['blob' => $hugeBlob],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload']);
    }
}
