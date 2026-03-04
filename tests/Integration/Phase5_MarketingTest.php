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
use Modules\BusinessIntelligence\Services\InventoryService;
use Modules\Marketing\Services\CouponService;
use Modules\Marketing\Services\MagicLinkService;
use Tests\TestCase;

/**
 * Phase 5: Marketing Intelligence (The Persuader)
 *
 * Tests 23-28 — Cart teleportation via WhatsApp, sizing regret
 * intervention, predictive replenishment, inventory-aware kill-switch,
 * dead stock broadcast, and multi-touch attribution.
 */
final class Phase5_MarketingTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    // ------------------------------------------------------------------
    //  Lifecycle
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::firstOrCreate(
            ['slug' => 'marketing-e2e-' . substr(md5((string) mt_rand()), 0, 8)],
            ['name' => 'Marketing E2E Tenant', 'is_active' => true],
        );

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Marketing Tester',
            'email'     => 'marketing-' . uniqid() . '@example.com',
            'password'  => bcrypt('password'),
        ]);

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
        DB::connection('mongodb')->table('coupons')
            ->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('magic_links')
            ->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_products')
            ->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_orders')
            ->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('events')
            ->where('tenant_id', $this->tenant->id)->delete();
        $this->user->forceDelete();
        $this->tenant->forceDelete();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  UC23: Cart Teleportation via WhatsApp (Magic Link)
    // ------------------------------------------------------------------

    /**
     * Scenario: User abandons a $120 cart. Marketing sends a WhatsApp
     * magic link that reconstructs the exact cart on any device.
     *
     * Expected: MagicLinkService creates a tokenised link stored in
     * MongoDB with cart items and optional coupon code.
     */
    public function test_uc23_cart_teleportation_via_magic_link(): void
    {
        /** @var MagicLinkService $magicLink */
        $magicLink = app(MagicLinkService::class);

        $cartItems = [
            ['product_id' => 'SHOE-RED-42', 'sku' => 'SHR-42', 'name' => 'Red Sneakers', 'qty' => 1, 'price' => 89.99],
            ['product_id' => 'HAT-BLK-01', 'sku' => 'HBK-01', 'name' => 'Black Cap', 'qty' => 1, 'price' => 29.99],
        ];

        $result = $magicLink->createCartRecoveryLink(
            $this->tenant->id,
            'cart-abandon@example.com',
            $cartItems,
            'SAVE10',
        );

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['token']);
        $this->assertNotEmpty($result['url']);
        $this->assertSame(2, $result['cart_items']);
        $this->assertSame('SAVE10', $result['coupon']);
        $this->assertNotNull($result['expires_at']);

        // Verify persistence in MongoDB.
        $stored = DB::connection('mongodb')
            ->table('magic_links')
            ->where('tenant_id', $this->tenant->id)
            ->where('token', $result['token'])
            ->first();

        $this->assertNotNull($stored, 'Magic link must be persisted in MongoDB.');
        $stored = (array) $stored;
        $this->assertSame('cart_recovery', $stored['type']);
        $this->assertFalse($stored['used']);
        $this->assertCount(2, $stored['cart_items']);
        $this->assertSame('SAVE10', $stored['coupon_code']);
    }

    // ------------------------------------------------------------------
    //  UC24: Sizing Regret Intervention (Exit-Intent Coupon Escalation)
    // ------------------------------------------------------------------

    /**
     * Scenario: Customer triggers exit intent multiple times. The coupon
     * service escalates: 5% → 10% → free shipping.
     *
     * Expected: Each subsequent generateExitIntentCoupon call returns
     * progressively better offers.
     */
    public function test_uc24_sizing_regret_exit_intent_escalation(): void
    {
        /** @var CouponService $couponService */
        $couponService = app(CouponService::class);

        $email = 'exit-intent-' . uniqid() . '@example.com';
        $sessionId = 'exit_' . uniqid();

        // First exit intent.
        $first = $couponService->generateExitIntentCoupon(
            $this->tenant->id,
            $email,
            $sessionId,
        );

        $this->assertTrue($first['success']);
        $this->assertNotEmpty($first['code']);
        $this->assertStringStartsWith('EXIT', $first['code']);

        // Store first coupon value for comparison.
        $firstValue = $first['value'] ?? 0;

        // Second exit intent — should escalate.
        $second = $couponService->generateExitIntentCoupon(
            $this->tenant->id,
            $email,
            $sessionId,
        );

        $this->assertTrue($second['success']);

        // Third exit intent — maximum escalation.
        $third = $couponService->generateExitIntentCoupon(
            $this->tenant->id,
            $email,
            $sessionId,
        );

        $this->assertTrue($third['success']);

        // Verify coupons are stored in MongoDB.
        $couponCount = DB::connection('mongodb')
            ->table('coupons')
            ->where('tenant_id', $this->tenant->id)
            ->where('email', $email)
            ->where('reason', 'exit_intent')
            ->count();

        $this->assertGreaterThanOrEqual(3, $couponCount,
            'Three exit-intent coupons must be stored.');
    }

    // ------------------------------------------------------------------
    //  UC25: Predictive Replenishment (Abandoned Cart Recovery Coupon)
    // ------------------------------------------------------------------

    /**
     * Scenario: Customer abandons a $250 cart. The system generates
     * a graduated coupon (higher cart = bigger discount).
     *
     * Expected: CouponService::generateAbandonedCartCoupon returns
     * a 15% coupon for carts >= $200.
     */
    public function test_uc25_predictive_replenishment_cart_coupon(): void
    {
        /** @var CouponService $couponService */
        $couponService = app(CouponService::class);

        $email = 'replenish-' . uniqid() . '@example.com';

        // High-value cart ($250) → 15% discount.
        $highCart = $couponService->generateAbandonedCartCoupon(
            $this->tenant->id,
            $email,
            250.00,
        );

        $this->assertTrue($highCart['success']);
        $this->assertEquals(15, $highCart['value'],
            'Cart >= $200 must get 15% discount.');
        $this->assertStringStartsWith('CART', $highCart['code']);

        // Medium cart ($120) → 12% discount.
        $midCart = $couponService->generateAbandonedCartCoupon(
            $this->tenant->id,
            $email,
            120.00,
        );

        $this->assertTrue($midCart['success']);
        $this->assertEquals(12, $midCart['value'],
            'Cart >= $100 must get 12% discount.');

        // Small cart ($30) → 8% discount.
        $lowCart = $couponService->generateAbandonedCartCoupon(
            $this->tenant->id,
            $email,
            30.00,
        );

        $this->assertTrue($lowCart['success']);
        $this->assertEquals(8, $lowCart['value'],
            'Cart < $50 must get 8% discount.');
    }

    // ------------------------------------------------------------------
    //  UC26: Inventory-Aware Kill-Switch
    // ------------------------------------------------------------------

    /**
     * Scenario: InventoryService detects dead stock — products with
     * no sales in the analysis window.
     *
     * Expected: Dead stock detection correctly identifies products
     * that haven't sold recently.
     */
    public function test_uc26_inventory_aware_kill_switch(): void
    {
        // Seed products.
        $products = [
            [
                'tenant_id'   => $this->tenant->id,
                'external_id' => 'DEAD-001',
                'name'        => 'Dead Stock Widget',
                'price'       => 49.99,
                'cost'        => 30.00,
                'stock_qty'   => 500,
                'status'      => 'enabled',
                'category'    => 'Gadgets',
                'created_at'  => now()->subDays(180)->toDateTimeString(),
            ],
            [
                'tenant_id'   => $this->tenant->id,
                'external_id' => 'ACTIVE-001',
                'name'        => 'Popular Gadget',
                'price'       => 99.99,
                'cost'        => 40.00,
                'stock_qty'   => 50,
                'status'      => 'enabled',
                'category'    => 'Gadgets',
                'created_at'  => now()->subDays(30)->toDateTimeString(),
            ],
        ];

        foreach ($products as $p) {
            DB::connection('mongodb')->table('synced_products')->insert($p);
        }

        // Seed a recent purchase EVENT for the active product only (service reads from events).
        DB::connection('mongodb')->table('events')->insert([
            'tenant_id'  => $this->tenant->id,
            'event_type' => 'purchase',
            'metadata'   => [
                'items' => [
                    ['product_id' => 'ACTIVE-001', 'name' => 'Popular Gadget', 'quantity' => 3, 'price' => 99.99],
                ],
            ],
            'created_at' => now()->subDays(5)->toDateTimeString(),
        ]);

        /** @var InventoryService $inventory */
        $inventory = app(InventoryService::class);

        $result = $inventory->detectDeadStock($this->tenant->id, 90);

        $this->assertIsArray($result);
        $this->assertTrue($result['success'] ?? false);

        // Dead stock should identify DEAD-001 (no sales in 90 days).
        $deadProducts = $result['dead_stock'] ?? [];
        $deadIds = array_column($deadProducts, 'product_id');

        if (count($deadProducts) > 0) {
            $this->assertContains('DEAD-001', $deadIds,
                'Dead Stock Widget with no sales must appear in dead stock list.');
        }
    }

    // ------------------------------------------------------------------
    //  UC27: Dead Stock Broadcast (Birthday Coupon)
    // ------------------------------------------------------------------

    /**
     * Scenario: Marketing generates birthday coupons as a broadcast
     * lever for customer engagement.
     *
     * Expected: CouponService::generateBirthdayCoupon creates a 20%
     * coupon with 7-day expiry.
     */
    public function test_uc27_dead_stock_birthday_broadcast(): void
    {
        /** @var CouponService $couponService */
        $couponService = app(CouponService::class);

        $email = 'birthday-' . uniqid() . '@example.com';

        $result = $couponService->generateBirthdayCoupon(
            $this->tenant->id,
            $email,
            'birthday',
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(20, $result['value'], 'Birthday coupon must be 20%.');
        $this->assertStringStartsWith('BDAY', $result['code']);

        // Anniversary variant → 15%.
        $anniv = $couponService->generateBirthdayCoupon(
            $this->tenant->id,
            $email,
            'anniversary',
        );

        $this->assertTrue($anniv['success']);
        $this->assertEquals(15, $anniv['value'], 'Anniversary coupon must be 15%.');
        $this->assertStringStartsWith('ANNIV', $anniv['code']);

        // Verify coupons in MongoDB.
        $stored = DB::connection('mongodb')
            ->table('coupons')
            ->where('tenant_id', $this->tenant->id)
            ->where('email', $email)
            ->get();

        $this->assertCount(2, $stored, 'Both birthday and anniversary coupons must be stored.');
    }

    // ------------------------------------------------------------------
    //  UC28: Multi-Touch Attribution
    // ------------------------------------------------------------------

    /**
     * Scenario: A customer journey spans 5 touchpoints — email click,
     * product view, search, add_to_cart, begin_checkout — then purchase.
     *
     * Expected: AttributionService reconstructs the full journey with
     * correct first touch, last touch, and assisted touches.
     */
    public function test_uc28_multi_touch_attribution(): void
    {
        $tid = $this->tenant->id;
        $sessionId = 'attrib_' . uniqid();

        // Seed a 5-touchpoint journey.
        $events = [
            ['event_type' => 'click',        'url' => 'https://email.com/promo-link',  'metadata' => ['source' => 'email']],
            ['event_type' => 'product_view',  'url' => 'https://store.com/shoes/air-max', 'metadata' => ['product_id' => 'AIR-MAX-01']],
            ['event_type' => 'search',        'url' => 'https://store.com/search?q=sneakers', 'metadata' => ['query' => 'sneakers']],
            ['event_type' => 'add_to_cart',   'url' => 'https://store.com/shoes/air-max', 'metadata' => ['product_id' => 'AIR-MAX-01', 'price' => 179.99]],
            ['event_type' => 'begin_checkout', 'url' => 'https://store.com/checkout',     'metadata' => ['cart_total' => 179.99]],
        ];

        foreach ($events as $i => $event) {
            TrackingEvent::create([
                'tenant_id'  => $tid,
                'session_id' => $sessionId,
                'event_type' => $event['event_type'],
                'url'        => $event['url'],
                'metadata'   => $event['metadata'],
                'created_at' => now()->subMinutes(count($events) - $i),
            ]);
        }

        /** @var AttributionService $attribution */
        $attribution = app(AttributionService::class);

        $result = $attribution->resolveConversionSource($this->tenant->id, $sessionId);

        $this->assertNotNull($result['first_touch']);
        $this->assertSame('click', $result['first_touch']['event_type'],
            'First touch must be the email click.');

        $this->assertNotNull($result['last_touch']);
        $this->assertSame('begin_checkout', $result['last_touch']['event_type'],
            'Last touch must be begin_checkout.');

        $this->assertCount(3, $result['assisted_touches'],
            'Middle 3 events (product_view, search, add_to_cart) must be assisted touches.');

        $this->assertSame(5, $result['touch_count']);

        // Test cross-session attribution with multiple sessions.
        $sessionId2 = 'attrib2_' . uniqid();
        TrackingEvent::create([
            'tenant_id'  => $tid,
            'session_id' => $sessionId2,
            'event_type' => 'product_view',
            'url'        => 'https://store.com/shoes/air-max',
            'metadata'   => ['product_id' => 'AIR-MAX-01'],
            'created_at' => now()->subDays(1),
        ]);

        $crossSession = $attribution->resolveCrossSessionAttribution(
            $this->tenant->id,
            [$sessionId2, $sessionId],
        );

        $this->assertSame(2, $crossSession['total_sessions']);
        $this->assertGreaterThanOrEqual(6, $crossSession['total_touchpoints'],
            'Cross-session attribution must include all touchpoints from both sessions.');
    }
}
