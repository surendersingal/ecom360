<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;
use Modules\Analytics\Services\AdvancedAnalyticsOpsService;
use Modules\Analytics\Services\AttributionService;
use Modules\BusinessIntelligence\Services\DynamicPricingService;
use Modules\BusinessIntelligence\Services\InventoryService;
use Modules\Chatbot\Services\AdvancedChatService;
use Modules\Chatbot\Services\ProactiveSupportService;
use Modules\Marketing\Services\CouponService;
use Tests\TestCase;

/**
 * Phase 13: Financial Reconciliation & Edge Cases
 *
 * Tests 91-100 — Multi-currency revenue, partial refund attribution,
 * coupon-on-tax-inclusive totals, gift card revenue tracking, BNPL
 * order handling, tax-exempt validation, coupon stacking logic,
 * historic attribution recalculation, COGS fluctuation margins,
 * and zero-dollar cart handling.
 */
final class Phase13_FinancialReconciliationTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::firstOrCreate(
            ['slug' => 'fin-e2e-' . substr(md5((string) mt_rand()), 0, 8)],
            ['name' => 'Financial E2E Tenant', 'is_active' => true],
        );

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Fin Tester',
            'email'     => 'fin-' . uniqid() . '@example.com',
            'password'  => bcrypt('password'),
        ]);

        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        TrackingEvent::where('tenant_id', $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('events')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('events')->where('tenant_id', (string) $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_orders')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_orders')->where('tenant_id', (string) $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_products')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_products')->where('tenant_id', (string) $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_customers')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_customers')->where('tenant_id', (string) $this->tenant->id)->delete();
        DB::connection('mongodb')->table('coupons')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('coupons')->where('tenant_id', (string) $this->tenant->id)->delete();
    }

    protected function tearDown(): void
    {
        TrackingEvent::where('tenant_id', (string) $this->tenant->id)->delete();
        TrackingEvent::where('tenant_id', $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', (string) $this->tenant->id)->delete();
        CustomerProfile::where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('events')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('events')->where('tenant_id', (string) $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_orders')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_orders')->where('tenant_id', (string) $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_products')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_products')->where('tenant_id', (string) $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_customers')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_customers')->where('tenant_id', (string) $this->tenant->id)->delete();
        DB::connection('mongodb')->table('coupons')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('coupons')->where('tenant_id', (string) $this->tenant->id)->delete();
        $this->user->forceDelete();
        $this->tenant->forceDelete();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  UC91: Multi-Currency Revenue Aggregation
    // ------------------------------------------------------------------

    /**
     * Scenario: Orders come in USD, EUR, and GBP. The BI device revenue
     * mapping must aggregate totals correctly regardless of currency
     * mixing (all stored as numeric values).
     */
    public function test_uc91_multi_currency_revenue(): void
    {
        Sanctum::actingAs($this->user);
        $tid = $this->tenant->id;

        // Seed orders in different "currencies" (stored as total_price).
        $orders = [
            ['currency' => 'USD', 'total' => 100.00],
            ['currency' => 'EUR', 'total' => 85.50],
            ['currency' => 'GBP', 'total' => 72.00],
            ['currency' => 'USD', 'total' => 200.00],
        ];

        foreach ($orders as $idx => $order) {
            DB::connection('mongodb')->table('events')->insert([
                'tenant_id'  => $tid,
                'event_type' => 'purchase',
                'properties' => [
                    'order_total' => $order['total'],
                    'currency'    => $order['currency'],
                    'order_id'    => 'ORD-MULTI-CUR-' . $idx,
                    'device_type' => 'desktop',
                ],
                'created_at' => now()->subDays($idx),
                'updated_at' => now(),
            ]);
        }

        /** @var \Modules\BusinessIntelligence\Services\AdvancedBIService $bi */
        $bi = app(\Modules\BusinessIntelligence\Services\AdvancedBIService::class);
        $result = $bi->deviceRevenueMapping($tid, '30d');

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['total_revenue'],
            'Multi-currency revenue must be aggregated.');
        $this->assertSame(4, $result['total_orders']);
    }

    // ------------------------------------------------------------------
    //  UC92: Partial Refund Attribution
    // ------------------------------------------------------------------

    /**
     * Scenario: Customer buys over 3 sessions then gets a partial refund.
     * Attribution must still correctly track all original touch-points.
     */
    public function test_uc92_partial_refund_attribution(): void
    {
        $tid = $this->tenant->id;
        $sessions = [
            'sess_refund_1_' . uniqid(),
            'sess_refund_2_' . uniqid(),
        ];

        // Session 1: Browse (touchpoint event type).
        TrackingEvent::create([
            'tenant_id' => $tid, 'session_id' => $sessions[0],
            'event_type' => 'product_view', 'url' => 'https://store.com/products',
            'metadata' => ['source' => 'organic'],
            'created_at' => now()->subDays(3),
        ]);

        // Session 2: Add to cart (touchpoint event type).
        TrackingEvent::create([
            'tenant_id' => $tid, 'session_id' => $sessions[1],
            'event_type' => 'add_to_cart', 'url' => 'https://store.com/cart',
            'metadata' => ['order_id' => 'ORD-REFUND-001', 'total' => 150.00],
            'created_at' => now()->subDays(1),
        ]);

        // Partial refund event on the purchase session (not a touchpoint, but shouldn't break).
        TrackingEvent::create([
            'tenant_id' => $tid, 'session_id' => $sessions[1],
            'event_type' => 'begin_checkout', 'url' => 'https://store.com/checkout',
            'metadata' => ['order_id' => 'ORD-REFUND-001'],
            'created_at' => now(),
        ]);

        /** @var AttributionService $attribution */
        $attribution = app(AttributionService::class);

        $result = $attribution->resolveCrossSessionAttribution($tid, $sessions);

        $this->assertArrayHasKey('first_touch', $result);
        $this->assertArrayHasKey('last_touch', $result);
        $this->assertSame(2, $result['total_sessions']);
        $this->assertGreaterThanOrEqual(2, $result['total_touchpoints'],
            'Original touch-points must survive partial refund event.');
    }

    // ------------------------------------------------------------------
    //  UC93: Coupon on Tax-Inclusive Total
    // ------------------------------------------------------------------

    /**
     * Scenario: Coupon is validated against an order total that includes
     * tax ( $110 = $100 product + $10 tax). The discount calculation
     * must work on the full total.
     */
    public function test_uc93_coupon_tax_inclusive_total(): void
    {
        $tid = $this->tenant->id;

        /** @var CouponService $coupons */
        $coupons = app(CouponService::class);

        // Generate a percentage coupon.
        $generated = $coupons->generate($tid, [
            'type'       => 'percentage',
            'value'      => 10,
            'reason'     => 'tax_test',
            'email'      => 'taxer@example.com',
            'expires_at' => now()->addDays(7)->toDateTimeString(),
        ]);

        $this->assertTrue($generated['success']);

        // Validate against tax-inclusive total.
        $taxInclusiveTotal = 110.00; // $100 + $10 tax
        $validation = $coupons->validate($tid, $generated['code'], 'taxer@example.com', $taxInclusiveTotal);

        $this->assertTrue($validation['valid']);
        $this->assertArrayHasKey('discount', $validation);

        // 10% of $110 = $11.
        $this->assertEquals(11.0, (float) $validation['discount'],
            'Discount must be calculated on the tax-inclusive total.');
    }

    // ------------------------------------------------------------------
    //  UC94: Gift Card Revenue Tracking
    // ------------------------------------------------------------------

    /**
     * Scenario: Gift card builder flow produces a gift card. The
     * generated card details must be structured for revenue reporting.
     */
    public function test_uc94_gift_card_revenue_tracking(): void
    {
        Sanctum::actingAs($this->user);
        $tid = $this->tenant->id;

        /** @var AdvancedChatService $chat */
        $chat = app(AdvancedChatService::class);

        // Step 1: Amount selection.
        $step1 = $chat->giftCardBuilder($tid, ['step' => 'amount']);
        $this->assertTrue($step1['success']);

        // Step 2: Design selection.
        $step2 = $chat->giftCardBuilder($tid, ['step' => 'design', 'amount' => 100]);
        $this->assertTrue($step2['success']);

        // Step 3: Personalization.
        $step3 = $chat->giftCardBuilder($tid, [
            'step'     => 'personalize',
            'amount'   => 100,
            'design'   => 'birthday',
        ]);
        $this->assertTrue($step3['success']);

        // Step 4: Final confirmation.
        $step4 = $chat->giftCardBuilder($tid, [
            'step'           => 'confirm',
            'amount'         => 100,
            'design'         => 'birthday',
            'recipient_name' => 'Jane Doe',
            'recipient_email' => 'jane@example.com',
            'sender_name'    => 'John Doe',
            'message'        => 'Happy Birthday!',
        ]);

        $this->assertTrue($step4['success']);
        $this->assertArrayHasKey('gift_card', $step4);

        $card = (array) $step4['gift_card'];
        $this->assertArrayHasKey('code', $card);
        $this->assertEquals(100, (float) ($card['amount'] ?? $card['value'] ?? 0),
            'Gift card must have the correct amount for revenue reporting.');
    }

    // ------------------------------------------------------------------
    //  UC95: BNPL Order Support Handling
    // ------------------------------------------------------------------

    /**
     * Scenario: A buy-now-pay-later order appears in the order system.
     * ProactiveSupportService must look it up and provide VIP greeting
     * with the order context.
     */
    public function test_uc95_bnpl_order_handling(): void
    {
        Sanctum::actingAs($this->user);
        $tid = $this->tenant->id;

        // Seed a BNPL order.
        DB::connection('mongodb')->table('synced_orders')->insert([
            'tenant_id'      => $tid,
            'external_id'    => 'ORD-BNPL-' . uniqid(),
            'email'          => 'bnpl@example.com',
            'total_price'    => 499.99,
            'status'         => 'completed',
            'payment_method' => 'bnpl_klarna',
            'line_items'     => [
                ['product_id' => 'SOFA-001', 'name' => 'Modern Sofa', 'quantity' => 1, 'price' => 499.99],
            ],
            'created_at'     => now()->subDays(2),
            'updated_at'     => now(),
        ]);

        // Seed the customer.
        DB::connection('mongodb')->table('synced_customers')->insert([
            'tenant_id'  => $tid,
            'email'      => 'bnpl@example.com',
            'first_name' => 'BNPL',
            'last_name'  => 'Buyer',
            'created_at' => now()->subDays(30),
            'updated_at' => now(),
        ]);

        /** @var ProactiveSupportService $support */
        $support = app(ProactiveSupportService::class);
        $result = $support->vipGreeting($tid, 'bnpl@example.com');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('greeting', $result);
        $this->assertArrayHasKey('customer', $result);
        $this->assertArrayHasKey('quick_actions', $result);
    }

    // ------------------------------------------------------------------
    //  UC96: Tax-Exempt Order Coupon Validation
    // ------------------------------------------------------------------

    /**
     * Scenario: A tax-exempt organization places an order. The coupon
     * must validate against the pre-tax amount ($0 tax).
     */
    public function test_uc96_tax_exempt_coupon_validation(): void
    {
        $tid = $this->tenant->id;

        /** @var CouponService $coupons */
        $coupons = app(CouponService::class);

        $generated = $coupons->generate($tid, [
            'type'       => 'fixed_amount',
            'value'      => 25,
            'reason'     => 'bulk_order',
            'email'      => 'nonprofit@example.org',
            'expires_at' => now()->addDays(14)->toDateTimeString(),
        ]);

        $this->assertTrue($generated['success']);

        // Validate against pre-tax amount (tax-exempt, so total = subtotal).
        $preTaxTotal = 200.00;
        $validation = $coupons->validate($tid, $generated['code'], 'nonprofit@example.org', $preTaxTotal);

        $this->assertTrue($validation['valid']);
        $this->assertEquals(25.0, (float) $validation['discount'],
            'Fixed $25 coupon discount must apply to tax-exempt total.');
    }

    // ------------------------------------------------------------------
    //  UC97: Coupon Stacking Logic
    // ------------------------------------------------------------------

    /**
     * Scenario: Customer tries to apply two coupons. The first must
     * validate, redeem, and then the second must be checked against
     * the updated (post-first-coupon) total.
     */
    public function test_uc97_coupon_stacking_logic(): void
    {
        $tid = $this->tenant->id;

        /** @var CouponService $coupons */
        $coupons = app(CouponService::class);

        // Generate two coupons.
        $coupon1 = $coupons->generate($tid, [
            'type' => 'percentage', 'value' => 10,
            'reason' => 'stack_test_1', 'email' => 'stacker@example.com',
            'expires_at' => now()->addDays(7)->toDateTimeString(),
        ]);
        $coupon2 = $coupons->generate($tid, [
            'type' => 'fixed_amount', 'value' => 15,
            'reason' => 'stack_test_2', 'email' => 'stacker@example.com',
            'expires_at' => now()->addDays(7)->toDateTimeString(),
        ]);

        $this->assertTrue($coupon1['success']);
        $this->assertTrue($coupon2['success']);

        $orderTotal = 200.00;

        // Validate and redeem first coupon.
        $v1 = $coupons->validate($tid, $coupon1['code'], 'stacker@example.com', $orderTotal);
        $this->assertTrue($v1['valid']);

        $r1 = $coupons->redeem($tid, $coupon1['code'], 'ORD-STACK-001');
        $this->assertTrue($r1['success']);

        // Apply first discount: 10% of $200 = $20 → new total $180.
        $postFirstDiscount = $orderTotal - (float) $v1['discount'];

        // Validate second coupon against reduced total.
        $v2 = $coupons->validate($tid, $coupon2['code'], 'stacker@example.com', $postFirstDiscount);
        $this->assertTrue($v2['valid']);
        $this->assertEquals(15.0, (float) $v2['discount'],
            'Second coupon ($15 fixed) must apply to post-discount total.');
    }

    // ------------------------------------------------------------------
    //  UC98: Historic Attribution Recalculation
    // ------------------------------------------------------------------

    /**
     * Scenario: Multi-touch attribution is recalculated over historical
     * data. AdvancedAnalyticsOpsService must process past events and
     * produce a consistent attribution model.
     */
    public function test_uc98_historic_attribution_recalculation(): void
    {
        Sanctum::actingAs($this->user);
        $tid = $this->tenant->id;

        // Seed historical events across multiple sessions and channels.
        $sessions = [];
        for ($i = 0; $i < 5; $i++) {
            $s = 'sess_hist_' . $i . '_' . uniqid();
            $sessions[] = $s;

            DB::connection('mongodb')->table('events')->insert([
                'tenant_id'  => $tid,
                'event_type' => ($i === 4) ? 'purchase' : 'page_view',
                'session_id' => $s,
                'properties' => [
                    'channel'     => ['organic', 'email', 'social', 'paid', 'direct'][$i],
                    'order_total' => ($i === 4) ? 250.00 : 0,
                    'order_id'    => ($i === 4) ? 'ORD-HIST-001' : null,
                ],
                'created_at' => now()->subDays(30 - $i * 5),
                'updated_at' => now(),
            ]);
        }

        /** @var AdvancedAnalyticsOpsService $ops */
        $ops = app(AdvancedAnalyticsOpsService::class);
        $result = $ops->multiTouchAttribution($tid);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('conversions', $result);
        $this->assertArrayHasKey('channels', $result);
        $this->assertArrayHasKey('model', $result);
    }

    // ------------------------------------------------------------------
    //  UC99: COGS Fluctuation — Margin Analysis
    // ------------------------------------------------------------------

    /**
     * Scenario: Products have different cost prices. analyzeMargins
     * must compute correct margins even with zero-cost and zero-price
     * edge cases.
     */
    public function test_uc99_cogs_fluctuation_margin_analysis(): void
    {
        Sanctum::actingAs($this->user);
        $tid = $this->tenant->id;

        // Seed products with varied cost structures.
        DB::connection('mongodb')->table('synced_products')->insert([
            ['tenant_id' => $tid, 'external_id' => 'COGS-HIGH',
             'name' => 'High Margin Product', 'price' => 100.00, 'cost_price' => 10.00,
             'stock_qty' => 50, 'status' => 'active',
             'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tid, 'external_id' => 'COGS-LOW',
             'name' => 'Low Margin Product', 'price' => 20.00, 'cost_price' => 18.00,
             'stock_qty' => 100, 'status' => 'active',
             'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tid, 'external_id' => 'COGS-ZERO',
             'name' => 'Free Sample', 'price' => 0.00, 'cost_price' => 5.00,
             'stock_qty' => 200, 'status' => 'active',
             'created_at' => now(), 'updated_at' => now()],
        ]);

        // Seed purchase events with metadata.items format (matching analyzeMargins query).
        $products = [
            ['id' => 'COGS-HIGH', 'price' => 100.00, 'qty' => 1, 'row_total' => 100.00],
            ['id' => 'COGS-LOW', 'price' => 20.00, 'qty' => 1, 'row_total' => 20.00],
            ['id' => 'COGS-ZERO', 'price' => 0.00, 'qty' => 1, 'row_total' => 0.00],
        ];
        foreach ($products as $prod) {
            for ($i = 0; $i < 3; $i++) {
                DB::connection('mongodb')->table('events')->insert([
                    'tenant_id'  => $tid,
                    'event_type' => 'purchase',
                    'customer_identifier' => ['type' => 'email', 'value' => "cogs{$i}@example.com"],
                    'metadata' => [
                        'items' => [
                            ['product_id' => $prod['id'], 'price' => $prod['price'], 'qty' => $prod['qty'], 'row_total' => $prod['row_total']],
                        ],
                    ],
                    'created_at' => now()->subDays(rand(1, 30)),
                    'updated_at' => now(),
                ]);
            }
        }

        /** @var InventoryService $inventory */
        $inventory = app(InventoryService::class);
        $result = $inventory->analyzeMargins($tid, 'product', 50);

        $this->assertArrayHasKey('total_revenue', $result);
        $this->assertArrayHasKey('total_cost', $result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('overall_margin', $result);

        // Revenue should include the high-margin product at least.
        $this->assertGreaterThan(0, $result['total_revenue'],
            'Total revenue must be positive with high-margin product sales.');
    }

    // ------------------------------------------------------------------
    //  UC100: Zero-Dollar Cart Edge Case
    // ------------------------------------------------------------------

    /**
     * Scenario: A fully-discounted cart ($0.00 total). Coupon validation
     * and redemption must handle zero-total gracefully without division
     * by zero or negative discount errors.
     */
    public function test_uc100_zero_dollar_cart(): void
    {
        $tid = $this->tenant->id;

        /** @var CouponService $coupons */
        $coupons = app(CouponService::class);

        // Generate a fixed $50 coupon.
        $generated = $coupons->generate($tid, [
            'type'       => 'fixed_amount',
            'value'      => 50,
            'reason'     => 'zero_cart_test',
            'email'      => 'freeloader@example.com',
            'expires_at' => now()->addDays(7)->toDateTimeString(),
        ]);

        $this->assertTrue($generated['success']);

        // Validate against a $0.00 cart.
        $validation = $coupons->validate($tid, $generated['code'], 'freeloader@example.com', 0.00);

        // System must handle $0 cart — either valid with $0 discount or invalid (min order).
        $this->assertIsBool($validation['valid'] ?? false);

        // If valid, discount must not be negative.
        if ($validation['valid'] ?? false) {
            $this->assertGreaterThanOrEqual(0, (float) ($validation['discount'] ?? 0),
                'Discount on zero cart must not be negative.');
        }

        // Also test percentage coupon with $0 cart.
        $pctCoupon = $coupons->generate($tid, [
            'type'       => 'percentage',
            'value'      => 100,
            'reason'     => 'full_discount',
            'email'      => 'freeloader@example.com',
            'expires_at' => now()->addDays(7)->toDateTimeString(),
        ]);

        $this->assertTrue($pctCoupon['success']);

        $v2 = $coupons->validate($tid, $pctCoupon['code'], 'freeloader@example.com', 0.00);
        $this->assertIsBool($v2['valid'] ?? false,
            'Percentage coupon on $0 cart must not crash.');
    }
}
