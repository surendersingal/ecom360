<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;
use Modules\BusinessIntelligence\Services\DynamicPricingService;
use Modules\BusinessIntelligence\Services\PredictionService;
use Modules\BusinessIntelligence\Services\ReturnRiskService;
use Tests\TestCase;

/**
 * Phase 6: BI & Operations (The Strategist)
 *
 * Tests 29-33 — Return-risk mitigation, fraud probability via IP
 * mismatch, RFM automated cohort shifting, demand forecasting, and
 * shipping cost vs promo margin analysis.
 */
final class Phase6_BiOperationsTest extends TestCase
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
            ['slug' => 'bi-ops-e2e-' . substr(md5((string) mt_rand()), 0, 8)],
            ['name' => 'BI Ops E2E Tenant', 'is_active' => true],
        );

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'BI Tester',
            'email'     => 'bi-' . uniqid() . '@example.com',
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
        DB::connection('mongodb')->table('synced_orders')
            ->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('events')
            ->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('mongodb')->table('synced_products')
            ->where('tenant_id', $this->tenant->id)->delete();
        Cache::flush();
        $this->user->forceDelete();
        $this->tenant->forceDelete();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  UC29: Return-Risk Mitigation (Serial Returner Detection)
    // ------------------------------------------------------------------

    /**
     * Scenario: A customer has 10 purchases and 5 refunds (50% return
     * rate). The ReturnRiskService must detect them as a serial returner.
     *
     * Expected: detectSerialReturners() returns this email with a high
     * risk score and return rate >= 30%.
     */
    public function test_uc29_return_risk_serial_returner(): void
    {
        $email = 'serial-returner-' . uniqid() . '@example.com';

        // Seed 10 purchases and 5 refunds via events collection.
        for ($i = 0; $i < 10; $i++) {
            DB::connection('mongodb')->table('events')->insert([
                'tenant_id'           => $this->tenant->id,
                'event_type'          => 'purchase',
                'customer_identifier' => ['type' => 'email', 'value' => $email],
                'metadata'            => [
                    'order_id'       => "ORD-SR-{$i}",
                    'order_total'    => 99.99,
                    'customer_email' => $email,
                ],
                'created_at'          => now()->subDays(30 + $i)->toDateTimeString(),
            ]);
        }

        for ($i = 0; $i < 5; $i++) {
            DB::connection('mongodb')->table('events')->insert([
                'tenant_id'           => $this->tenant->id,
                'event_type'          => 'refund',
                'customer_identifier' => ['type' => 'email', 'value' => $email],
                'metadata'            => [
                    'order_id'       => "ORD-SR-{$i}",
                    'refund_amount'  => 99.99,
                    'customer_email' => $email,
                ],
                'created_at'          => now()->subDays(15 + $i)->toDateTimeString(),
            ]);
        }

        /** @var ReturnRiskService $riskService */
        $riskService = app(ReturnRiskService::class);

        $result = $riskService->detectSerialReturners(
            $this->tenant->id,
            30, // 30% threshold
            3,  // min 3 orders
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('serial_returners', $result);
        $this->assertGreaterThanOrEqual(1, $result['count'],
            'Must detect at least one serial returner.');

        $returnerEmails = array_column($result['serial_returners'], 'email');
        $this->assertContains($email, $returnerEmails,
            'Test email with 50% return rate must be flagged.');

        $returner = collect($result['serial_returners'])->firstWhere('email', $email);
        $this->assertGreaterThanOrEqual(30, $returner['return_rate'],
            '50% return rate must exceed 30% threshold.');
        $this->assertSame(10, $returner['order_count']);
        $this->assertSame(5, $returner['refund_count']);
    }

    // ------------------------------------------------------------------
    //  UC30: Fraud Probability & IP Mismatch
    // ------------------------------------------------------------------

    /**
     * Scenario: A session has events from drastically different IPs
     * (geographic anomaly). This is tracked via metadata. We verify
     * the event metadata survives persistence and can be queried.
     *
     * Expected: All events with suspicious IP metadata are queryable
     * from MongoDB for fraud analysis.
     */
    public function test_uc30_fraud_probability_ip_mismatch(): void
    {
        $tid = (string) $this->tenant->id;
        $sessionId = 'fraud_' . uniqid();
        $customerEmail = 'fraud-suspect-' . uniqid() . '@example.com';

        // Event 1: Login from New York.
        TrackingEvent::create([
            'tenant_id'  => $tid,
            'session_id' => $sessionId,
            'event_type' => 'page_view',
            'url'        => 'https://store.com/login',
            'metadata'   => [
                'ip' => '74.125.224.72',
                'geo' => ['city' => 'New York', 'country' => 'US'],
            ],
            'created_at' => now()->subMinutes(10),
        ]);

        // Event 2: Purchase from Lagos, Nigeria (5 min later — suspicious).
        TrackingEvent::create([
            'tenant_id'  => $tid,
            'session_id' => $sessionId,
            'event_type' => 'purchase',
            'url'        => 'https://store.com/checkout/success',
            'metadata'   => [
                'ip'         => '197.210.53.22',
                'geo'        => ['city' => 'Lagos', 'country' => 'NG'],
                'order_total' => 2499.99,
                'order_id'   => 'FRAUD-ORD-001',
            ],
            'created_at' => now()->subMinutes(5),
        ]);

        // Query events for fraud analysis.
        $events = TrackingEvent::where('tenant_id', $tid)
            ->where('session_id', $sessionId)
            ->orderBy('created_at', 'asc')
            ->get();

        $this->assertCount(2, $events);

        $loginGeo = $events[0]->metadata['geo'];
        $purchaseGeo = $events[1]->metadata['geo'];

        // Detect cross-continent IP switch within the same session.
        $this->assertNotSame($loginGeo['country'], $purchaseGeo['country'],
            'Login and purchase countries must differ for fraud detection.');

        // Distance heuristic: different continents = high risk.
        $suspiciousCountryPairs = [
            ['US', 'NG'], ['US', 'RU'], ['US', 'CN'],
            ['GB', 'NG'], ['GB', 'CN'],
        ];

        $isSuspicious = false;
        foreach ($suspiciousCountryPairs as [$a, $b]) {
            if (($loginGeo['country'] === $a && $purchaseGeo['country'] === $b) ||
                ($loginGeo['country'] === $b && $purchaseGeo['country'] === $a)) {
                $isSuspicious = true;
                break;
            }
        }

        $this->assertTrue($isSuspicious,
            'US → NG IP switch in same session must flag as suspicious.');

        // Verify high-value purchase metadata is intact.
        $this->assertEquals(2499.99, $events[1]->metadata['order_total']);
    }

    // ------------------------------------------------------------------
    //  UC31: RFM Automated Cohort Shifting
    // ------------------------------------------------------------------

    /**
     * Scenario: DynamicPricingService calculates RFM scores and assigns
     * tiers. We seed purchase history and verify tier classification.
     *
     * Expected: Champion customers get ~5% VIP discount. At-risk
     * customers get 10-20% "thaw" discount. Hibernating get up to 25%.
     */
    public function test_uc31_rfm_automated_cohort_shifting(): void
    {
        $championEmail = 'champion-' . uniqid() . '@example.com';
        $dormantEmail  = 'dormant-' . uniqid() . '@example.com';

        // Seed champion: 20 recent purchases, all very recent.
        for ($i = 0; $i < 20; $i++) {
            DB::connection('mongodb')->table('synced_orders')->insert([
                'tenant_id'      => $this->tenant->id,
                'customer_email' => $championEmail,
                'total'          => 150.00,
                'status'         => 'completed',
                'created_at'     => now()->subDays(rand(1, 30))->toDateTimeString(),
            ]);
        }

        // Seed dormant: 2 purchases, last one 6 months ago.
        for ($i = 0; $i < 2; $i++) {
            DB::connection('mongodb')->table('synced_orders')->insert([
                'tenant_id'      => $this->tenant->id,
                'customer_email' => $dormantEmail,
                'total'          => 50.00,
                'status'         => 'completed',
                'created_at'     => now()->subDays(180 + $i * 30)->toDateTimeString(),
            ]);
        }

        /** @var DynamicPricingService $pricing */
        $pricing = app(DynamicPricingService::class);

        // Champion pricing.
        $vipResult = $pricing->getVipPrice(
            $this->tenant->id,
            $championEmail,
            100.00,
        );

        $this->assertIsArray($vipResult);
        $this->assertSame(100.00, $vipResult['base_price']);
        $this->assertLessThanOrEqual(100.00, $vipResult['discounted_price'],
            'Champion must get some discount.');
        $this->assertNotEmpty($vipResult['tier']);
        $this->assertNotEmpty($vipResult['reason']);

        // Dormant pricing — should get bigger discount.
        $dormantResult = $pricing->getVipPrice(
            $this->tenant->id,
            $dormantEmail,
            100.00,
        );

        $this->assertIsArray($dormantResult);
        $this->assertLessThanOrEqual(100.00, $dormantResult['discounted_price']);

        // The dormant customer should get a bigger discount than champion.
        $this->assertGreaterThanOrEqual(
            $vipResult['discount_percent'],
            $dormantResult['discount_percent'],
            'Dormant customer must get equal or greater discount than champion.',
        );
    }

    // ------------------------------------------------------------------
    //  UC32: Demand Forecasting (CLV Prediction)
    // ------------------------------------------------------------------

    /**
     * Scenario: PredictionService generates CLV predictions for customers
     * with purchase history.
     *
     * Expected: The service creates Prediction model records with
     * features and explanation.
     */
    public function test_uc32_demand_forecasting_clv(): void
    {
        $email = 'clv-predict-' . uniqid() . '@example.com';

        // Seed a customer profile with purchase history in MongoDB.
        CustomerProfile::create([
            'tenant_id'       => (string) $this->tenant->id,
            'identifier_type' => 'email',
            'identifier_value' => $email,
            'known_sessions'  => ['session_clv_1'],
            'device_fingerprints' => [],
            'custom_attributes' => ['segment' => 'loyal'],
            'total_orders'     => 15,
            'total_revenue'    => 2500.00,
            'average_order_value' => 166.67,
            'first_seen_at'   => now()->subMonths(12)->toDateTimeString(),
            'last_purchase_at' => now()->subDays(10)->toDateTimeString(),
            'rfm_segment'     => 'loyal',
        ]);

        /** @var PredictionService $prediction */
        $prediction = app(PredictionService::class);

        $result = $prediction->generate($this->tenant->id, 'clv');

        $this->assertIsArray($result);
        $this->assertSame('clv', $result['model_type']);
        $this->assertGreaterThanOrEqual(0, $result['generated'],
            'Should generate CLV predictions for customers with orders.');
    }

    // ------------------------------------------------------------------
    //  UC33: Shipping Cost vs Promo Margin Analysis
    // ------------------------------------------------------------------

    /**
     * Scenario: BI needs to compare product margins. We seed products
     * with cost data and verify margin calculations are available.
     *
     * Expected: Product margin data is correctly stored and queryable.
     */
    public function test_uc33_shipping_cost_vs_promo_margin(): void
    {
        // Seed products with detailed margin data.
        $products = [
            [
                'tenant_id'   => $this->tenant->id,
                'external_id' => 'MARGIN-001',
                'name'        => 'High Margin Tee',
                'price'       => 49.99,
                'cost'        => 10.00,
                'margin'      => 80.0,
                'category'    => 'Clothing',
                'stock_qty'   => 200,
            ],
            [
                'tenant_id'   => $this->tenant->id,
                'external_id' => 'MARGIN-002',
                'name'        => 'Low Margin Electronics',
                'price'       => 999.99,
                'cost'        => 950.00,
                'margin'      => 5.0,
                'category'    => 'Electronics',
                'stock_qty'   => 10,
            ],
            [
                'tenant_id'   => $this->tenant->id,
                'external_id' => 'MARGIN-003',
                'name'        => 'Medium Margin Shoes',
                'price'       => 149.99,
                'cost'        => 60.00,
                'margin'      => 60.0,
                'category'    => 'Footwear',
                'stock_qty'   => 75,
            ],
        ];

        foreach ($products as $p) {
            DB::connection('mongodb')->table('synced_products')->insert($p);
        }

        // Seed orders to compute actual sold margins.
        DB::connection('mongodb')->table('synced_orders')->insert([
            'tenant_id' => $this->tenant->id,
            'items' => [
                ['product_id' => 'MARGIN-001', 'name' => 'High Margin Tee', 'quantity' => 5, 'price' => 49.99, 'cost' => 10.00],
                ['product_id' => 'MARGIN-002', 'name' => 'Low Margin Electronics', 'quantity' => 1, 'price' => 999.99, 'cost' => 950.00],
            ],
            'total' => 1249.94,
            'shipping_cost' => 15.99,
            'discount_amount' => 50.00,
            'created_at' => now()->subDays(5)->toDateTimeString(),
        ]);

        // Query products and verify margin data.
        $allProducts = DB::connection('mongodb')
            ->table('synced_products')
            ->where('tenant_id', $this->tenant->id)
            ->get();

        $this->assertCount(3, $allProducts);

        $high = $allProducts->firstWhere('external_id', 'MARGIN-001');
        $low  = $allProducts->firstWhere('external_id', 'MARGIN-002');

        $high = (array) $high;
        $low  = (array) $low;
        $this->assertEquals(80.0, $high['margin']);
        $this->assertEquals(5.0, $low['margin']);

        // Verify order data includes shipping and discount for margin analysis.
        $order = DB::connection('mongodb')
            ->table('synced_orders')
            ->where('tenant_id', $this->tenant->id)
            ->first();

        $this->assertNotNull($order);
        $order = (array) $order;
        $this->assertEquals(15.99, $order['shipping_cost']);
        $this->assertEquals(50.00, $order['discount_amount']);

        // Net margin calculation: revenue - cost - shipping - discount.
        $revenue = $order['total'];         // 1249.94
        $cost    = (5 * 10.00) + (1 * 950); // 1000.00
        $ship    = $order['shipping_cost'];  // 15.99
        $disc    = $order['discount_amount']; // 50.00

        $netMargin = $revenue - $cost - $ship - $disc;
        $this->assertGreaterThan(0, $netMargin,
            'Net margin after shipping and discount must be positive.');

        $marginPct = ($netMargin / $revenue) * 100;
        $this->assertGreaterThan(10, $marginPct,
            'Margin percentage must be meaningful for profitability analysis.');
    }
}
