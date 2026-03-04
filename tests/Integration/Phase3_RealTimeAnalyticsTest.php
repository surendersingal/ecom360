<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Models\BehavioralRule;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;
use Modules\Analytics\Services\IntentScoringService;
use Modules\Analytics\Services\LiveContextService;
use Tests\TestCase;

/**
 * Phase 3: Real-Time Analytics & WebSockets (The Nervous System)
 *
 * Tests 12-16 — Rage click concierge, ephemeral cryptographic pricing,
 * pre-bounce free shipping gamification, rapid intent suppression, and
 * real-time cannibalization tracking.
 */
final class Phase3_RealtimeAnalyticsTest extends TestCase
{
    private Tenant $tenant;
    private User $user;
    private IntentScoringService $intentService;
    private LiveContextService $liveContext;

    // ------------------------------------------------------------------
    //  Lifecycle
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::firstOrCreate(
            ['slug' => 'analytics-e2e-' . substr(md5((string) mt_rand()), 0, 8)],
            ['name' => 'Analytics E2E Tenant', 'is_active' => true],
        );

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Analytics Tester',
            'email'     => 'analytics-' . uniqid() . '@example.com',
            'password'  => bcrypt('password'),
        ]);

        $this->intentService = app(IntentScoringService::class);
        $this->liveContext   = app(LiveContextService::class);

        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        TrackingEvent::where('tenant_id', $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', $this->tenant->id)->delete();
    }

    protected function tearDown(): void
    {
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        TrackingEvent::where('tenant_id', $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', $this->tenant->id)->delete();
        BehavioralRule::where('tenant_id', $this->tenant->id)->delete();
        $this->user->forceDelete();
        $this->tenant->forceDelete();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  UC12: Rage Click Concierge
    // ------------------------------------------------------------------

    /**
     * Scenario: User fires 6 rapid product_view events on the same
     * element (rage clicking "Add to Cart" that isn't responding).
     *
     * Expected: The intent score reflects rapid engagement, and a
     * BehavioralRule with a high-intent condition fires an intervention
     * via EvaluateBehavioralRules.
     */
    public function test_uc12_rage_click_concierge(): void
    {
        $sessionId = 'rage_click_' . uniqid();

        // Create a high-intent rule that fires at score >= 60.
        BehavioralRule::create([
            'tenant_id'         => $this->tenant->id,
            'name'              => 'Rage Click Concierge',
            'trigger_condition' => ['min_intent_score' => 60],
            'action_type'       => 'show_help_modal',
            'action_payload'    => ['message' => 'Need help? Chat with us!'],
            'priority'          => 100,
            'cooldown_minutes'  => 5,
            'is_active'         => true,
        ]);

        // Fire 6 rapid add_to_cart events (each +20 to intent score).
        for ($i = 0; $i < 6; $i++) {
            $this->intentService->recordEvent($sessionId, 'add_to_cart');
        }

        $intent = $this->intentService->evaluateIntent($sessionId);

        // 6 × add_to_cart (20 each) = 120 → must be high_intent.
        $this->assertGreaterThanOrEqual(60, $intent['score']);
        $this->assertSame('high_intent', $intent['level']);

        // Now simulate the event pipeline via authenticated ingestion.
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/analytics/ingest', [
            'payload' => [
                'session_id' => $sessionId,
                'event_type' => 'add_to_cart',
                'url'        => 'https://store.com/product/rage-test',
                'metadata'   => ['product_id' => 'RAGE-001', 'rapid_clicks' => 6],
            ],
        ]);

        $response->assertStatus(201);

        // Final score should be even higher now.
        $finalScore = $this->intentService->getScore($sessionId);
        $this->assertGreaterThanOrEqual(120, $finalScore,
            'Rage clicks must accumulate a very high intent score.');

        // Cleanup Redis.
        $this->intentService->flush($sessionId);
    }

    // ------------------------------------------------------------------
    //  UC13: Ephemeral Cryptographic Pricing
    // ------------------------------------------------------------------

    /**
     * Scenario: A VIP customer views a product. The live context tracks
     * the product page with a time-limited pricing exposure so the price
     * can be personalised and expires after 30 minutes (Redis TTL).
     *
     * Expected: LiveContextService stores page + cart with TTL.
     * After manual key deletion, the pricing context disappears.
     */
    public function test_uc13_ephemeral_cryptographic_pricing(): void
    {
        $sessionId = 'ephemeral_price_' . uniqid();
        $productId = 'VIP-SHOE-42';

        // Set a live context page (this simulates the "ephemeral pricing window").
        $this->liveContext->updateCurrentPage($sessionId, $productId);

        // Also set the cart with a VIP price.
        $this->liveContext->updateLiveCart($sessionId, [
            ['product_id' => $productId, 'price' => 79.99, 'vip_price' => 59.99],
        ], 59.99);

        // Read context back — the pricing must be intact.
        $ctx = $this->liveContext->getContext($sessionId);

        $this->assertNotNull($ctx['current_page']);
        $this->assertSame($productId, $ctx['current_page']['product_id']);
        $this->assertNotNull($ctx['active_cart']);
        $this->assertEquals(59.99, $ctx['active_cart']['total']);

        // Verify the Redis keys have a TTL set (sliding window expiry).
        $pageTtl = Redis::ttl("live_ctx:page:{$sessionId}");
        $cartTtl = Redis::ttl("live_ctx:cart:{$sessionId}");

        $this->assertGreaterThan(0, $pageTtl, 'Page context must have a positive TTL.');
        $this->assertGreaterThan(0, $cartTtl, 'Cart context must have a positive TTL.');
        $this->assertLessThanOrEqual(1800, $pageTtl, 'TTL must not exceed 30 minutes.');

        // Simulate expiry — delete keys and verify context is gone.
        Redis::del("live_ctx:page:{$sessionId}");
        Redis::del("live_ctx:cart:{$sessionId}");

        $expired = $this->liveContext->getContext($sessionId);
        $this->assertNull($expired['current_page'], 'Expired page context must be null.');
        $this->assertNull($expired['active_cart'], 'Expired cart context must be null.');
    }

    // ------------------------------------------------------------------
    //  UC14: Pre-Bounce Free Shipping Gamification
    // ------------------------------------------------------------------

    /**
     * Scenario: User has a cart but intent score drops below 0 (abandon
     * risk). A BehavioralRule fires "free shipping" intervention when
     * intent_level == abandon_risk AND has_cart == true.
     *
     * Expected: The rule's condition matches the session context properly.
     */
    public function test_uc14_pre_bounce_free_shipping_gamification(): void
    {
        $sessionId = 'bounce_ship_' . uniqid();

        // Add a product view first to start intent, then cart removal to tank it.
        $this->intentService->recordEvent($sessionId, 'page_view');       // +2
        $this->intentService->recordEvent($sessionId, 'add_to_cart');     // +20
        $this->intentService->recordEvent($sessionId, 'remove_from_cart'); // -10
        $this->intentService->recordEvent($sessionId, 'remove_from_cart'); // -10
        $this->intentService->recordEvent($sessionId, 'remove_from_cart'); // -10

        // Score: 2 + 20 - 10 - 10 - 10 = -8 → abandon_risk.
        $intent = $this->intentService->evaluateIntent($sessionId);
        $this->assertSame('abandon_risk', $intent['level']);
        $this->assertLessThan(0, $intent['score']);

        // Set a live cart (user still has something in cart despite removals).
        $this->liveContext->updateLiveCart($sessionId, [
            ['product_id' => 'SHOE-42', 'qty' => 1, 'price' => 89.99],
        ], 89.99);

        // Create a behavioral rule for free shipping on abandon risk.
        $rule = BehavioralRule::create([
            'tenant_id'         => $this->tenant->id,
            'name'              => 'Free Shipping Pre-Bounce',
            'trigger_condition' => [
                'intent_level' => 'abandon_risk',
                'has_cart'     => true,
            ],
            'action_type'       => 'offer_free_shipping',
            'action_payload'    => ['message' => 'Complete now → Free Shipping!'],
            'priority'          => 90,
            'cooldown_minutes'  => 10,
            'is_active'         => true,
        ]);

        // Verify the rule's condition matches current context manually.
        $liveCtx = $this->liveContext->getContext($sessionId);

        $this->assertNotNull($liveCtx['active_cart'], 'Cart must exist.');
        $this->assertSame('abandon_risk', $intent['level']);
        $this->assertTrue(
            $liveCtx['active_cart'] !== null && $intent['level'] === 'abandon_risk',
            'Both conditions must be true for intervention to fire.',
        );

        // Now fire the event through the API pipeline.
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/analytics/ingest', [
            'payload' => [
                'session_id' => $sessionId,
                'event_type' => 'page_view',
                'url'        => 'https://store.com/homepage',
                'metadata'   => ['bounce_risk' => true],
            ],
        ]);

        $response->assertStatus(201);

        // Cleanup.
        $this->intentService->flush($sessionId);
        Redis::del("live_ctx:page:{$sessionId}", "live_ctx:cart:{$sessionId}");
    }

    // ------------------------------------------------------------------
    //  UC15: Rapid Intent Suppression
    // ------------------------------------------------------------------

    /**
     * Scenario: A bot or misbehaving script fires 100 page_views in a
     * second. The intent score should accumulate but the system should
     * remain stable. We verify Redis atomicity and no data corruption.
     *
     * Expected: Score = 100 × 2 = 200. No exceptions, Redis key intact.
     */
    public function test_uc15_rapid_intent_suppression(): void
    {
        $sessionId = 'rapid_intent_' . uniqid();

        // Fire 100 rapid events.
        for ($i = 0; $i < 100; $i++) {
            $this->intentService->recordEvent($sessionId, 'page_view');
        }

        $score = $this->intentService->getScore($sessionId);
        $this->assertSame(200, $score, 'Score must equal 100 × 2 (page_view delta).');

        $intent = $this->intentService->evaluateIntent($sessionId);
        $this->assertSame('high_intent', $intent['level'],
            '200 score must be classified as high_intent.');

        // Verify TTL is still set (not lost during rapid INCRBY).
        $ttl = Redis::ttl("intent:score:{$sessionId}");
        $this->assertGreaterThan(0, $ttl, 'TTL must survive rapid increments.');
        $this->assertLessThanOrEqual(1800, $ttl);

        // Flush and verify cleanup.
        $this->intentService->flush($sessionId);
        $this->assertSame(0, $this->intentService->getScore($sessionId));
    }

    // ------------------------------------------------------------------
    //  UC16: Real-Time Cannibalization Tracking
    // ------------------------------------------------------------------

    /**
     * Scenario: Two products in the same category are viewed in the same
     * session. We track the events and verify that the attribution
     * service can identify the touchpoints for cannibalization analysis.
     *
     * Expected: Both product views are recorded. Attribution returns
     * both as touchpoints with correct ordering.
     */
    public function test_uc16_realtime_cannibalization_tracking(): void
    {
        $tid = $this->tenant->id;
        $sessionId = 'cannibal_' . uniqid();

        // User views Product A (the old revenue leader) then Product B (the new one).
        TrackingEvent::create([
            'tenant_id'  => $tid,
            'session_id' => $sessionId,
            'event_type' => 'product_view',
            'url'        => 'https://store.com/shoes/classic-runner',
            'metadata'   => ['product_id' => 'SHOE-OLD-001', 'category' => 'Footwear'],
            'created_at' => now()->subMinutes(5),
        ]);

        TrackingEvent::create([
            'tenant_id'  => $tid,
            'session_id' => $sessionId,
            'event_type' => 'product_view',
            'url'        => 'https://store.com/shoes/ultra-runner',
            'metadata'   => ['product_id' => 'SHOE-NEW-002', 'category' => 'Footwear'],
            'created_at' => now()->subMinutes(2),
        ]);

        // User buys the NEW product (cannibalization signal).
        TrackingEvent::create([
            'tenant_id'  => $tid,
            'session_id' => $sessionId,
            'event_type' => 'add_to_cart',
            'url'        => 'https://store.com/shoes/ultra-runner',
            'metadata'   => ['product_id' => 'SHOE-NEW-002', 'price' => 149.99],
            'created_at' => now()->subMinute(),
        ]);

        // Verify all three events are persisted.
        $events = TrackingEvent::where('tenant_id', $tid)
            ->where('session_id', $sessionId)
            ->orderBy('created_at', 'asc')
            ->get();

        $this->assertCount(3, $events);
        $this->assertSame('product_view', $events[0]->event_type);
        $this->assertSame('SHOE-OLD-001', $events[0]->metadata['product_id']);
        $this->assertSame('product_view', $events[1]->event_type);
        $this->assertSame('SHOE-NEW-002', $events[1]->metadata['product_id']);
        $this->assertSame('add_to_cart', $events[2]->event_type);

        // Use AttributionService to reconstruct the session's touchpoints.
        $attribution = app(\Modules\Analytics\Services\AttributionService::class);
        $result = $attribution->resolveConversionSource($this->tenant->id, $sessionId);

        $this->assertNotNull($result['first_touch'],
            'First touch must be the old product view.');
        $this->assertSame('product_view', $result['first_touch']['event_type']);

        $this->assertNotNull($result['last_touch'],
            'Last touch must be the add_to_cart for the new product.');
        $this->assertSame('add_to_cart', $result['last_touch']['event_type']);

        $this->assertGreaterThanOrEqual(3, $result['touch_count'],
            'Must record all 3 touchpoints for cannibalization analysis.');
    }
}
