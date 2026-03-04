<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Events\IntegrationEvent;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\E2ECustomerSeeder;
use Database\Seeders\E2ETenantSeeder;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Listeners\EvaluateBehavioralRules;
use Modules\Analytics\Models\BehavioralRule;
use Modules\Analytics\Models\TrackingEvent;
use Modules\Analytics\Services\IntentScoringService;
use Tests\TestCase;

/**
 * E2E Journey 2 — Live Intent Scoring & WebSocket Trigger.
 *
 * Proves the Real-Time Intent Scoring and Behavioral Rules engine
 * works end-to-end against real Redis and MySQL data.
 *
 * Flow:
 *  1. Three add_to_cart payloads are sent via the API (each worth +20 pts).
 *  2. The IntentScoringService scores them in Redis (3 × 20 = 60).
 *  3. EvaluateBehavioralRules evaluates the seeded rule
 *     (min_intent_score >= 50) and fires the intervention.
 *  4. We verify the physical Redis state and the cooldown key that
 *     proves FrontendInterventionRequired was dispatched.
 *
 * BROADCAST_CONNECTION=log in phpunit.xml ensures the broadcast goes
 * to the log file instead of requiring a running Reverb server.
 */
final class E2EIntentInterventionTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    private const string SESSION = 'e2e_intent_sess_001';

    // ------------------------------------------------------------------
    //  Lifecycle
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        (new E2ETenantSeeder())->run();
        (new E2ECustomerSeeder())->run();

        $this->tenant = Tenant::where('slug', E2ETenantSeeder::TENANT_SLUG)->firstOrFail();
        $this->user   = User::where('email', E2ETenantSeeder::USER_EMAIL)->firstOrFail();

        // Clean slate.
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        $this->flushRedis();
    }

    protected function tearDown(): void
    {
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        $this->flushRedis();
        parent::tearDown();
    }

    private function flushRedis(): void
    {
        Redis::del('intent:score:' . self::SESSION);

        // Redis::keys() returns fully-prefixed keys. We must strip the
        // prefix before passing them to Redis::del(), which re-adds it.
        $prefix = (string) config('database.redis.options.prefix', '');
        $raw    = Redis::keys('intervention:cooldown:*:' . self::SESSION);

        foreach ($raw as $key) {
            $stripped = str_starts_with($key, $prefix)
                ? substr($key, strlen($prefix))
                : $key;
            Redis::del($stripped);
        }
    }

    // ------------------------------------------------------------------
    //  Test
    // ------------------------------------------------------------------

    public function test_rapid_clicks_trigger_real_websocket_intervention(): void
    {
        Sanctum::actingAs($this->user);

        // ── Step 1: Send 3 add_to_cart events via the real API ───────
        //    Each hits IngestionController → TrackingService → MongoDB.
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->postJson('/api/v1/analytics/ingest', [
                'payload' => [
                    'session_id' => self::SESSION,
                    'event_type' => 'add_to_cart',
                    'url'        => "https://e2e-store.example.com/product/{$i}",
                    'metadata'   => ['product_id' => "e2e_prod_{$i}", 'price' => 39.99],
                ],
            ]);

            $response->assertStatus(201);
        }

        // Verify all 3 events landed in MongoDB.
        $mongoCount = TrackingEvent::query()
            ->where('tenant_id', (string) $this->tenant->id)
            ->where('session_id', self::SESSION)
            ->where('event_type', 'add_to_cart')
            ->count();

        $this->assertSame(3, $mongoCount, 'Expected 3 add_to_cart events in MongoDB.');

        // ── Step 2: Score intent in Redis ────────────────────────────
        //    In production, the RecordTrackingEvent listener (on the
        //    Redis queue) performs this scoring. Here we invoke the
        //    service directly to reproduce the exact same calculation
        //    without double-recording through the async listeners.
        $intentService = app(IntentScoringService::class);

        foreach (range(1, 3) as $ignored) {
            $intentService->recordEvent(self::SESSION, 'add_to_cart');
        }

        // ── Step 3: Assert the real Redis score is exactly 60 ────────
        $score = (int) Redis::get('intent:score:' . self::SESSION);
        $this->assertSame(60, $score, 'Intent score should be exactly 60 (3 × 20).');

        // ── Step 4: Evaluate behavioral rules (includes its OWN
        //    recordEvent call internally, which bumps score to 80). ────
        //    The seeded rule requires min_intent_score >= 50, so the
        //    intervention MUST fire.
        $listener = app(EvaluateBehavioralRules::class);
        $listener->handle(new IntegrationEvent(
            moduleName: 'analytics',
            eventName:  'tracking.ingest',
            payload: [
                'tenant_id'  => $this->tenant->id,
                'session_id' => self::SESSION,
                'event_type' => 'add_to_cart',
            ],
        ));

        // ── Step 5: Prove the intervention was dispatched ────────────
        //    The listener sets a Redis cooldown key ONLY after
        //    successfully calling broadcast(FrontendInterventionRequired).
        //    Its existence is proof the intervention was fired.
        $rule = BehavioralRule::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', E2ETenantSeeder::RULE_NAME)
            ->firstOrFail();

        $cooldownKey = "intervention:cooldown:{$rule->id}:" . self::SESSION;

        $this->assertTrue(
            Redis::exists($cooldownKey) > 0,
            'Cooldown key missing — FrontendInterventionRequired was NOT dispatched.',
        );

        // The cooldown TTL should be positive and within the 5-minute window.
        $ttl = Redis::ttl($cooldownKey);
        $this->assertGreaterThan(0, $ttl, 'Cooldown TTL should be positive.');
        $this->assertLessThanOrEqual(300, $ttl, 'Cooldown TTL should not exceed 300 s.');

        // Final Redis intent score after the listener's extra recordEvent:
        // 60 (manual) + 20 (listener) = 80.
        $finalScore = (int) Redis::get('intent:score:' . self::SESSION);
        $this->assertSame(80, $finalScore, 'After listener evaluation, score should be 80.');
    }
}
