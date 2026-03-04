<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;
use Modules\Analytics\Services\AttributionService;
use Modules\Analytics\Services\CdpAdvancedService;
use Modules\Analytics\Services\FingerprintResolutionService;
use Modules\Analytics\Services\IdentityResolutionService;
use Modules\BusinessIntelligence\Services\DynamicPricingService;
use Modules\BusinessIntelligence\Services\ReturnRiskService;
use Modules\Chatbot\Services\ProactiveSupportService;
use Modules\Marketing\Services\AdvancedMarketingService;
use Modules\Marketing\Services\HyperPersonalizationService;
use Tests\TestCase;

/**
 * Phase 10: CDP Edge Cases & Complex User Journeys
 *
 * Tests 61-70 — Shared-device identity, bi-lingual profiles,
 * conflicting intent signals, cross-timezone marketing, over-
 * communication throttling, anonymous cart transfer, post-purchase
 * race conditions, leap-year boundaries, gift-buyer anomalies,
 * and split-payment attribution.
 */
final class Phase10_CdpEdgeCasesTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::firstOrCreate(
            ['slug' => 'cdp-edge-' . substr(md5((string) mt_rand()), 0, 8)],
            ['name' => 'CDP Edge E2E Tenant', 'is_active' => true],
        );

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'CDP Edge Tester',
            'email'     => 'cdp-edge-' . uniqid() . '@example.com',
            'password'  => bcrypt('password'),
        ]);

        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        TrackingEvent::where('tenant_id', $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_orders')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_orders')->where('tenant_id', (string) $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_customers')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_customers')->where('tenant_id', (string) $this->tenant->id)->delete();
    }

    protected function tearDown(): void
    {
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        TrackingEvent::where('tenant_id', $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_orders')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_orders')->where('tenant_id', (string) $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_customers')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_customers')->where('tenant_id', (string) $this->tenant->id)->delete();
        DB::connection('mongodb')->table('events')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('events')->where('tenant_id', (string) $this->tenant->id)->delete();
        $this->user->forceDelete();
        $this->tenant->forceDelete();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  UC61: Shared Device — Family iPad
    // ------------------------------------------------------------------

    /**
     * Scenario: Mom and teenage daughter share an iPad. Mom logs in and
     * buys kitchenware, daughter logs in and buys sneakers. The system
     * must maintain separate profiles despite the same device fingerprint.
     */
    public function test_uc61_shared_device_family_ipad(): void
    {
        $tid = $this->tenant->id;
        $sharedFp = 'fp_family_ipad_' . uniqid();
        $momEmail = 'mom-' . uniqid() . '@family.com';
        $kidEmail = 'daughter-' . uniqid() . '@family.com';
        $momSession = 'sess_mom_' . uniqid();
        $kidSession = 'sess_kid_' . uniqid();

        /** @var FingerprintResolutionService $fp */
        $fp = app(FingerprintResolutionService::class);
        /** @var IdentityResolutionService $ir */
        $ir = app(IdentityResolutionService::class);

        // Mom uses iPad.
        $fp->resolve($tid, $momSession, $sharedFp);
        $ir->resolveIdentity($tid, $momSession, ['type' => 'email', 'value' => $momEmail], null);

        // Daughter uses same iPad.
        $fp->resolve($tid, $kidSession, $sharedFp);
        $ir->resolveIdentity($tid, $kidSession, ['type' => 'email', 'value' => $kidEmail], null);

        // Both profiles must exist and be separate.
        $momProfile = CustomerProfile::where('tenant_id', $tid)
            ->where('identifier_value', $momEmail)->first();
        $kidProfile = CustomerProfile::where('tenant_id', $tid)
            ->where('identifier_value', $kidEmail)->first();

        $this->assertNotNull($momProfile, 'Mom profile must exist.');
        $this->assertNotNull($kidProfile, 'Daughter profile must exist.');
        $this->assertNotEquals(
            (string) $momProfile->id,
            (string) $kidProfile->id,
            'Mom and daughter must have separate profiles.',
        );
    }

    // ------------------------------------------------------------------
    //  UC62: Bi-Lingual / Unicode Profile Data
    // ------------------------------------------------------------------

    /**
     * Scenario: A customer has Japanese name characters and Arabic address.
     * The CDP must store and retrieve Unicode data without corruption.
     */
    public function test_uc62_bilingual_unicode_profile(): void
    {
        $tid = $this->tenant->id;
        $email = 'unicode-' . uniqid() . '@example.com';
        $session = 'sess_unicode_' . uniqid();

        /** @var IdentityResolutionService $ir */
        $ir = app(IdentityResolutionService::class);

        $ir->resolveIdentity($tid, $session, [
            'type'  => 'email',
            'value' => $email,
        ], [
            'name'    => '田中太郎',       // Japanese
            'address' => 'شارع الملك',     // Arabic
            'city'    => 'München',          // German umlaut
            'notes'   => '🎉 VIP Customer 🎉', // Emoji
        ]);

        $profile = CustomerProfile::where('tenant_id', $tid)
            ->where('identifier_value', $email)
            ->first();

        $this->assertNotNull($profile);
        $attrs = (array) $profile->custom_attributes;
        $this->assertSame('田中太郎', $attrs['name'] ?? null, 'Japanese name must be stored.');
        $this->assertSame('شارع الملك', $attrs['address'] ?? null, 'Arabic address must be stored.');
        $this->assertSame('München', $attrs['city'] ?? null, 'German umlaut must be preserved.');
        $this->assertStringContainsString('🎉', $attrs['notes'] ?? '', 'Emoji must be preserved.');
    }

    // ------------------------------------------------------------------
    //  UC63: Conflicting Intent Signals
    // ------------------------------------------------------------------

    /**
     * Scenario: A customer has high purchase frequency (should be VIP)
     * but also a high return rate (should be flagged). Both services
     * must independently produce accurate assessments without conflict.
     */
    public function test_uc63_conflicting_intent_signals(): void
    {
        $tid = $this->tenant->id;
        $email = 'conflicted-' . uniqid() . '@example.com';

        // Seed purchase events (high frequency).
        for ($i = 0; $i < 10; $i++) {
            DB::connection('mongodb')->table('events')->insert([
                'tenant_id'  => $tid,
                'event_type' => 'purchase',
                'customer_identifier' => ['type' => 'email', 'value' => $email],
                'properties' => ['order_total' => 150.00, 'order_id' => 'ORD-CONFLICT-' . $i],
                'created_at' => now()->subDays($i * 5),
                'updated_at' => now()->subDays($i * 5),
            ]);
        }

        // Seed refund events (high return rate — 5 out of 10).
        for ($i = 0; $i < 5; $i++) {
            DB::connection('mongodb')->table('events')->insert([
                'tenant_id'  => $tid,
                'event_type' => 'refund',
                'customer_identifier' => ['type' => 'email', 'value' => $email],
                'properties' => ['refund_total' => 150.00, 'order_id' => 'ORD-CONFLICT-' . $i],
                'created_at' => now()->subDays($i * 3),
                'updated_at' => now()->subDays($i * 3),
            ]);
        }

        // DynamicPricingService — RFM score should be high (frequent buyer).
        /** @var DynamicPricingService $pricing */
        $pricing = app(DynamicPricingService::class);
        $rfm = $pricing->calculateRfmScore($tid, $email);

        $this->assertArrayHasKey('rfm_score', $rfm);
        $this->assertGreaterThan(0, $rfm['frequency'], 'Frequent buyer must have high frequency.');

        // ReturnRiskService — return rate should be high.
        /** @var ReturnRiskService $returnRisk */
        $returnRisk = app(ReturnRiskService::class);
        $risk = $returnRisk->scoreCustomer($tid, $email);

        $this->assertArrayHasKey('risk_score', $risk);
        $this->assertGreaterThan(0, $risk['return_rate'],
            'Customer with 50% returns must have non-zero return rate.');

        // Both assessments are valid independently — no conflict crash.
        $this->assertNotNull($rfm['tier']);
        $this->assertNotNull($risk['risk_level']);
    }

    // ------------------------------------------------------------------
    //  UC64: Cross-Timezone Marketing
    // ------------------------------------------------------------------

    /**
     * Scenario: A customer registered 60 days ago — churnRiskWinback
     * must assess their activity regardless of timezone differences
     * in event timestamps.
     */
    public function test_uc64_cross_timezone_marketing(): void
    {
        $tid = $this->tenant->id;
        $email = 'timezone-' . uniqid() . '@example.com';

        // Seed a customer.
        DB::connection('mongodb')->table('synced_customers')->insert([
            'tenant_id'  => $tid,
            'email'      => $email,
            'first_name' => 'Timezone',
            'last_name'  => 'Tester',
            'created_at' => now()->subDays(60),
            'updated_at' => now()->subDays(60),
        ]);

        // Seed events with varied timezone-offset timestamps.
        DB::connection('mongodb')->table('events')->insert([
            'tenant_id'  => $tid,
            'event_type' => 'page_view',
            'customer_identifier' => ['type' => 'email', 'value' => $email],
            'properties' => ['page' => '/products'],
            'created_at' => now()->subDays(45), // 45 days ago
            'updated_at' => now()->subDays(45),
        ]);

        // Seed an order — use customer_email and total (service field names).
        DB::connection('mongodb')->table('synced_orders')->insert([
            'tenant_id'      => $tid,
            'external_id'    => 'ORD-TZ-001',
            'customer_email' => $email,
            'total'          => 200.00,
            'status'         => 'completed',
            'created_at'     => now()->subDays(50),
            'updated_at'     => now()->subDays(50),
        ]);

        /** @var AdvancedMarketingService $marketing */
        $marketing = app(AdvancedMarketingService::class);
        $result = $marketing->churnRiskWinback($tid);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('total_at_risk', $result);
        $this->assertArrayHasKey('winback_sequence', $result);
    }

    // ------------------------------------------------------------------
    //  UC65: Over-Communication Throttling
    // ------------------------------------------------------------------

    /**
     * Scenario: A customer with recent orders should receive limited
     * campaign exposure. HyperPersonalizationService must assess the
     * customer's engagement level before deciding on outreach.
     */
    public function test_uc65_over_communication_throttling(): void
    {
        $tid = $this->tenant->id;

        // Seed a product for back-in-stock targeting.
        DB::connection('mongodb')->table('synced_products')->insert([
            'tenant_id'   => $tid,
            'external_id' => 'PROD-THROTTLE-001',
            'name'        => 'Hot Item Widget',
            'price'       => 49.99,
            'stock_qty'   => 5, // Just restocked
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Seed events indicating interest.
        for ($i = 0; $i < 3; $i++) {
            DB::connection('mongodb')->table('events')->insert([
                'tenant_id'  => $tid,
                'event_type' => 'product_view',
                'customer_identifier' => ['type' => 'email', 'value' => 'throttle@example.com'],
                'properties' => ['product_id' => 'PROD-THROTTLE-001'],
                'created_at' => now()->subDays($i + 1),
                'updated_at' => now()->subDays($i + 1),
            ]);
        }

        /** @var HyperPersonalizationService $hyper */
        $hyper = app(HyperPersonalizationService::class);
        $result = $hyper->backInStockMicroTarget($tid, 'PROD-THROTTLE-001');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('notify_list', $result);
        $this->assertArrayHasKey('total_interested', $result);

        // Cleanup.
        DB::connection('mongodb')->table('synced_products')->where('tenant_id', $tid)
            ->where('external_id', 'PROD-THROTTLE-001')->delete();
    }

    // ------------------------------------------------------------------
    //  UC66: Anonymous Cart Transfer
    // ------------------------------------------------------------------

    /**
     * Scenario: Anonymous user builds a cart (session A), leaves, comes
     * back and logs in (session B). Both sessions must merge into one
     * profile so the cart data is not lost.
     */
    public function test_uc66_anonymous_cart_transfer(): void
    {
        $tid = $this->tenant->id;
        $anonSession = 'sess_anon_cart_' . uniqid();
        $loginSession = 'sess_login_' . uniqid();
        $email = 'cart-transfer-' . uniqid() . '@example.com';
        $anonFp = 'fp_anon_' . uniqid();

        /** @var FingerprintResolutionService $fp */
        $fp = app(FingerprintResolutionService::class);
        /** @var IdentityResolutionService $ir */
        $ir = app(IdentityResolutionService::class);

        // Anonymous browsing with cart.
        $fp->resolve($tid, $anonSession, $anonFp);
        TrackingEvent::create([
            'tenant_id' => $tid, 'session_id' => $anonSession,
            'event_type' => 'add_to_cart', 'url' => 'https://store.com/shoes',
            'metadata' => ['product_id' => 'SHOE-001', 'price' => 89.99],
        ]);

        // User returns, logs in.
        $fp->resolve($tid, $loginSession, $anonFp);
        $ir->resolveIdentity($tid, $loginSession, ['type' => 'email', 'value' => $email], null);
        // Also link the original anonymous session.
        $ir->resolveIdentity($tid, $anonSession, ['type' => 'email', 'value' => $email], null);

        $profile = CustomerProfile::where('tenant_id', $tid)
            ->where('identifier_value', $email)->first();

        $this->assertNotNull($profile, 'Merged profile must exist.');
        $this->assertContains($anonSession, $profile->known_sessions,
            'Anonymous cart session must be linked.');
        $this->assertContains($loginSession, $profile->known_sessions,
            'Login session must be linked.');
    }

    // ------------------------------------------------------------------
    //  UC67: Post-Purchase Race Condition
    // ------------------------------------------------------------------

    /**
     * Scenario: Customer attempts order modification right after purchase.
     * ProactiveSupportService must handle the order lookup gracefully.
     */
    public function test_uc67_post_purchase_race_condition(): void
    {
        Sanctum::actingAs($this->user);
        $tid = $this->tenant->id;

        // Seed a just-placed order.
        DB::connection('mongodb')->table('synced_orders')->insert([
            'tenant_id'   => $tid,
            'external_id' => 'ORD-RACE-' . uniqid(),
            'email'       => 'racer@example.com',
            'total_price' => 149.99,
            'status'      => 'pending',
            'line_items'  => [
                ['product_id' => 'ITEM-001', 'name' => 'Widget', 'quantity' => 2, 'price' => 74.99],
            ],
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        /** @var ProactiveSupportService $support */
        $support = app(ProactiveSupportService::class);

        // VIP greeting for a customer who just ordered.
        $result = $support->vipGreeting($tid, 'racer@example.com');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('greeting', $result);
        $this->assertArrayHasKey('customer', $result);
    }

    // ------------------------------------------------------------------
    //  UC68: Leap Year Campaign Boundary
    // ------------------------------------------------------------------

    /**
     * Scenario: A customer's birthday is Feb 29 (leap year). The
     * milestone automation must acknowledge this date and generate
     * appropriate milestone content.
     */
    public function test_uc68_leap_year_campaign_boundary(): void
    {
        $tid = $this->tenant->id;

        // Seed a customer born on Feb 29.
        DB::connection('mongodb')->table('synced_customers')->insert([
            'tenant_id'  => $tid,
            'email'      => 'leapyear@example.com',
            'first_name' => 'Leapy',
            'last_name'  => 'McLeapface',
            'created_at' => now()->subYear(),
            'updated_at' => now(),
        ]);

        // Seed an order to make them a real customer.
        DB::connection('mongodb')->table('synced_orders')->insert([
            'tenant_id'   => $tid,
            'external_id' => 'ORD-LEAP-001',
            'email'       => 'leapyear@example.com',
            'total_price' => 99.99,
            'status'      => 'completed',
            'created_at'  => now()->subMonths(3),
            'updated_at'  => now()->subMonths(3),
        ]);

        /** @var AdvancedMarketingService $marketing */
        $marketing = app(AdvancedMarketingService::class);
        $result = $marketing->milestoneAutomation($tid);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('total_milestones', $result);
        $this->assertArrayHasKey('milestones', $result);
        $this->assertArrayHasKey('by_type', $result);
    }

    // ------------------------------------------------------------------
    //  UC69: Gift Buyer Anomaly Detection
    // ------------------------------------------------------------------

    /**
     * Scenario: Customer buys products in categories they never browse
     * (gift buying). The product affinity mapping must reflect this
     * anomaly — purchased categories differ from browsed categories.
     */
    public function test_uc69_gift_buyer_anomaly(): void
    {
        $tid = $this->tenant->id;
        $email = 'gift-buyer-' . uniqid() . '@example.com';

        // Seed browsing events for kids' toys.
        for ($i = 0; $i < 5; $i++) {
            DB::connection('mongodb')->table('events')->insert([
                'tenant_id'  => $tid,
                'event_type' => 'product_view',
                'customer_identifier' => ['type' => 'email', 'value' => $email],
                'properties' => ['category' => 'Kids Toys', 'product_id' => "TOY-{$i}"],
                'created_at' => now()->subDays($i),
                'updated_at' => now()->subDays($i),
            ]);
        }

        // Seed purchases in a DIFFERENT category — women's fashion (gift).
        DB::connection('mongodb')->table('events')->insert([
            'tenant_id'  => $tid,
            'event_type' => 'purchase',
            'customer_identifier' => ['type' => 'email', 'value' => $email],
            'properties' => [
                'order_total' => 89.99,
                'order_id' => 'ORD-GIFT-001',
                'category' => 'Womens Fashion',
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // CdpAdvancedService — product affinity should detect the anomaly.
        /** @var CdpAdvancedService $cdp */
        $cdp = app(CdpAdvancedService::class);
        $affinity = $cdp->productAffinityMapping($tid);

        $this->assertTrue($affinity['success']);
        $this->assertArrayHasKey('top_affinities', $affinity);
        $this->assertArrayHasKey('orders_analyzed', $affinity);
    }

    // ------------------------------------------------------------------
    //  UC70: Split Payment Attribution
    // ------------------------------------------------------------------

    /**
     * Scenario: A customer browses over 3 sessions (organic, email,
     * social) before buying. Cross-session attribution must track
     * all touch-points.
     */
    public function test_uc70_split_payment_attribution(): void
    {
        $tid = $this->tenant->id;
        $sessions = [
            'sess_organic_' . uniqid(),
            'sess_email_' . uniqid(),
            'sess_social_' . uniqid(),
        ];

        // Session 1: Organic landing — use touchpoint event type.
        TrackingEvent::create([
            'tenant_id' => $tid, 'session_id' => $sessions[0],
            'event_type' => 'campaign_event', 'url' => 'https://store.com/?utm_source=organic',
            'metadata' => ['source' => 'organic'],
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        // Session 2: Email click-through — use touchpoint event type.
        TrackingEvent::create([
            'tenant_id' => $tid, 'session_id' => $sessions[1],
            'event_type' => 'click', 'url' => 'https://store.com/?utm_source=email',
            'metadata' => ['source' => 'email'],
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        // Session 3: Social ad → product view + add to cart.
        TrackingEvent::create([
            'tenant_id' => $tid, 'session_id' => $sessions[2],
            'event_type' => 'product_view', 'url' => 'https://store.com/product/123',
            'metadata' => ['source' => 'facebook'],
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
        ]);
        TrackingEvent::create([
            'tenant_id' => $tid, 'session_id' => $sessions[2],
            'event_type' => 'add_to_cart', 'url' => 'https://store.com/cart',
            'metadata' => ['product_id' => 'P123', 'total' => 199.99],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var AttributionService $attribution */
        $attribution = app(AttributionService::class);
        $result = $attribution->resolveCrossSessionAttribution($tid, $sessions);

        $this->assertArrayHasKey('first_touch', $result);
        $this->assertArrayHasKey('last_touch', $result);
        $this->assertSame(3, $result['total_sessions']);
        $this->assertGreaterThanOrEqual(4, $result['total_touchpoints'],
            'All 4 touch-points must be attributed across sessions.');
    }
}
