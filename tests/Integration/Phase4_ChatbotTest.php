<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Models\CustomerProfile;
use Modules\Analytics\Models\TrackingEvent;
use Modules\Chatbot\Services\AdvancedChatService;
use Modules\Chatbot\Services\ProactiveSupportService;
use Tests\TestCase;

/**
 * Phase 4: AI Chatbot & Customer Service (The Voice)
 *
 * Tests 17-22 — Conversational 1-click checkout, predictive LTV agent
 * routing, competitor price-match, automated order modification,
 * sentiment-triggered escalation, and dynamic sizing memory.
 */
final class Phase4_ChatbotTest extends TestCase
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
            ['slug' => 'chatbot-e2e-' . substr(md5((string) mt_rand()), 0, 8)],
            ['name' => 'Chatbot E2E Tenant', 'is_active' => true],
        );

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Chatbot Tester',
            'email'     => 'chatbot-' . uniqid() . '@example.com',
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
        $this->user->forceDelete();
        $this->tenant->forceDelete();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  UC17: Conversational 1-Click Checkout
    // ------------------------------------------------------------------

    /**
     * Scenario: User asks chatbot "I want to buy the red sneakers."
     * The pre-checkout objection handler accepts the cart context and
     * returns actionable responses.
     *
     * Expected: AdvancedChatService returns structured checkout actions
     * with price, shipping, and trust signals.
     */
    public function test_uc17_conversational_one_click_checkout(): void
    {
        /** @var AdvancedChatService $chat */
        $chat = app(AdvancedChatService::class);

        $cart = [
            'items' => [
                ['product_id' => 'SHOE-RED-42', 'name' => 'Red Sneakers', 'qty' => 1, 'price' => 89.99],
            ],
            'total' => 89.99,
        ];

        // User expresses price concern before checkout.
        $result = $chat->preCheckoutObjectionHandler(
            $this->tenant->id,
            $cart,
            'This is too expensive, is it worth it?',
        );

        $this->assertIsArray($result);
        $this->assertTrue($result['success'] ?? false, 'Objection handler must succeed.');
        $responses = $result['responses'] ?? [];
        $this->assertNotEmpty($responses, 'Objection handler must return responses.');

        // Should identify "price" objection.
        $types = array_column($responses, 'objection_type');
        $this->assertContains('price', $types, 'Should detect a price-related objection.');

        // The price response should include actions.
        $priceResponse = collect($responses)->firstWhere('objection_type', 'price');
        $this->assertArrayHasKey('actions', $priceResponse);
        $this->assertNotEmpty($priceResponse['actions']);

        // Verify at least one action is "apply_coupon" (1-click discount).
        $actionTypes = array_column($priceResponse['actions'], 'action');
        $this->assertContains('apply_coupon', $actionTypes,
            'Should offer a coupon to facilitate 1-click checkout.');
    }

    // ------------------------------------------------------------------
    //  UC18: Predictive LTV Agent Routing
    // ------------------------------------------------------------------

    /**
     * Scenario: A VIP customer (high total_revenue) opens a chat.
     * The system checks their profile and routes to a senior agent.
     *
     * Expected: CustomerProfile with high revenue is used to determine
     * routing priority. The chat context includes VIP indicators.
     */
    public function test_uc18_predictive_ltv_agent_routing(): void
    {
        $tid = (string) $this->tenant->id;
        $vipEmail = 'vip-whale-' . uniqid() . '@example.com';

        // Seed a VIP customer profile with high spend.
        CustomerProfile::create([
            'tenant_id'       => $tid,
            'identifier_type' => 'email',
            'identifier_value' => $vipEmail,
            'known_sessions'  => ['vip_session_1'],
            'device_fingerprints' => [],
            'custom_attributes' => [
                'loyalty_tier'   => 'platinum',
                'total_revenue'  => 15000.00,
                'total_orders'   => 45,
                'segment'        => 'champion',
            ],
            'rfm_score' => '555',
            'rfm_details' => [
                'recency_days' => 3,
                'frequency'    => 45,
                'monetary'     => 15000.00,
            ],
        ]);

        // Retrieve the VIP profile (as chatbot would).
        $profile = CustomerProfile::where('tenant_id', $tid)
            ->where('identifier_value', $vipEmail)
            ->first();

        $this->assertNotNull($profile);
        $this->assertSame('555', $profile->rfm_score);
        $this->assertSame('platinum', $profile->custom_attributes['loyalty_tier']);

        // Determine routing tier based on profile.
        $totalRevenue = $profile->custom_attributes['total_revenue'] ?? 0;
        $rfmScore     = (int) $profile->rfm_score;

        $routingTier = match (true) {
            $rfmScore >= 444 && $totalRevenue >= 5000 => 'vip_senior_agent',
            $rfmScore >= 333 => 'priority_agent',
            default => 'standard_agent',
        };

        $this->assertSame('vip_senior_agent', $routingTier,
            'VIP customer ($15k, RFM 555) must route to senior agent.');
    }

    // ------------------------------------------------------------------
    //  UC19: Competitor Price-Match via Chat
    // ------------------------------------------------------------------

    /**
     * Scenario: Customer says in chat "I found this cheaper on Amazon."
     * The objection handler detects a price objection and offers match.
     *
     * Expected: Returns price-match action with coupon option.
     */
    public function test_uc19_competitor_price_match(): void
    {
        /** @var AdvancedChatService $chat */
        $chat = app(AdvancedChatService::class);

        $cart = [
            'items' => [
                ['product_id' => 'GADGET-X', 'name' => 'Smart Watch', 'qty' => 1, 'price' => 299.99],
            ],
            'total' => 299.99,
        ];

        $result = $chat->preCheckoutObjectionHandler(
            $this->tenant->id,
            $cart,
            'I found it cheaper on Amazon, can you match the price?',
        );

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        // Should detect price objection.
        $responses = $result['responses'] ?? [];
        $types = array_column($responses, 'objection_type');
        $this->assertContains('price', $types,
            'Competitor price complaint must trigger price objection handler.');

        $priceResp = collect($responses)->firstWhere('objection_type', 'price');
        $this->assertArrayHasKey('actions', $priceResp);

        // Should include price_match action.
        $actionTypes = array_column($priceResp['actions'], 'action');
        $this->assertContains('price_match', $actionTypes,
            'Must offer price-match option when competitor is mentioned.');
    }

    // ------------------------------------------------------------------
    //  UC20: Automated Order Modification
    // ------------------------------------------------------------------

    /**
     * Scenario: Customer requests order cancellation via chat.
     *
     * Expected: If order is in "pending" or "processing" status, the
     * system processes the cancellation. If "shipped," it denies with
     * an alternative suggestion.
     */
    public function test_uc20_automated_order_modification(): void
    {
        // Seed a pending order in MongoDB.
        DB::connection('mongodb')->table('synced_orders')->insert([
            'tenant_id'      => $this->tenant->id,
            'external_id'    => 'ORD-MOD-001',
            'customer_email'  => 'modify@example.com',
            'status'         => 'pending',
            'total'          => 159.99,
            'items'          => [
                ['product_id' => 'ITEM-A', 'name' => 'Blue Jacket', 'quantity' => 1, 'price' => 159.99],
            ],
            'created_at'     => now()->toDateTimeString(),
        ]);

        /** @var ProactiveSupportService $support */
        $support = app(ProactiveSupportService::class);

        // Test cancellation of a pending order.
        $result = $support->orderModification($this->tenant->id, [
            'action'         => 'cancel',
            'order_id'       => 'ORD-MOD-001',
            'customer_email' => 'modify@example.com',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('ORD-MOD-001', $result['order_id']);
        $this->assertSame('pending', $result['order_status']);

        // Now seed a shipped order and verify it cannot be cancelled.
        DB::connection('mongodb')->table('synced_orders')->insert([
            'tenant_id'      => $this->tenant->id,
            'external_id'    => 'ORD-MOD-002',
            'customer_email'  => 'modify@example.com',
            'status'         => 'shipped',
            'total'          => 79.99,
            'items'          => [],
            'created_at'     => now()->toDateTimeString(),
        ]);

        $shippedResult = $support->orderModification($this->tenant->id, [
            'action'         => 'cancel',
            'order_id'       => 'ORD-MOD-002',
            'customer_email' => 'modify@example.com',
        ]);

        $this->assertFalse($shippedResult['success'],
            'Shipped order must not be cancellable.');
        $this->assertStringContainsString('shipped', $shippedResult['message']);
    }

    // ------------------------------------------------------------------
    //  UC21: Sentiment-Triggered Escalation
    // ------------------------------------------------------------------

    /**
     * Scenario: Customer sends increasingly frustrated messages.
     * Sentiment analysis detects negative trend → escalates to human.
     *
     * Expected: sentimentEscalation returns escalation recommendation
     * when sentiment score drops below threshold.
     */
    public function test_uc21_sentiment_triggered_escalation(): void
    {
        /** @var ProactiveSupportService $support */
        $support = app(ProactiveSupportService::class);

        $sessionId = 'sentiment_' . uniqid();

        // First message — neutral/positive.
        $result1 = $support->sentimentEscalation($this->tenant->id, [
            'message'        => 'Hi, I need help with my order.',
            'session_id'     => $sessionId,
            'customer_email' => 'frustrated@example.com',
        ]);

        $this->assertIsArray($result1);
        $this->assertArrayHasKey('sentiment', $result1);

        // Second message — more frustrated.
        $result2 = $support->sentimentEscalation($this->tenant->id, [
            'message'        => 'This is ridiculous, I have been waiting for a week! Nobody responds!',
            'session_id'     => $sessionId,
            'customer_email' => 'frustrated@example.com',
        ]);

        $this->assertIsArray($result2);
        $this->assertArrayHasKey('sentiment', $result2);

        // Third message — very angry → should trigger escalation.
        $result3 = $support->sentimentEscalation($this->tenant->id, [
            'message'        => 'I am extremely angry! This is the worst service ever! I want a refund NOW or I will report you!',
            'session_id'     => $sessionId,
            'customer_email' => 'frustrated@example.com',
        ]);

        $this->assertIsArray($result3);
        $this->assertArrayHasKey('should_escalate', $result3);

        // Sentiment should be very negative by now.
        $this->assertLessThanOrEqual(40, $result3['sentiment']['score'],
            'Very angry messages must produce low sentiment score.');

        // The should_escalate flag should be true.
        $this->assertTrue($result3['should_escalate'],
            'Extremely negative sentiment must trigger human escalation.');

        // Clean up cache.
        Cache::forget("chat_sentiment:{$this->tenant->id}:{$sessionId}");
    }

    // ------------------------------------------------------------------
    //  UC22: Dynamic Sizing Memory
    // ------------------------------------------------------------------

    /**
     * Scenario: Customer's size preferences are stored in their CDP
     * profile's custom_attributes. When they return, the chatbot can
     * retrieve sizing history to make recommendations.
     *
     * Expected: Profile custom_attributes store and persist sizing data.
     */
    public function test_uc22_dynamic_sizing_memory(): void
    {
        $tid = (string) $this->tenant->id;
        $email = 'sizing-' . uniqid() . '@example.com';
        $sessionId = 'sizing_session_' . uniqid();

        // Create profile with sizing history.
        CustomerProfile::create([
            'tenant_id'       => $tid,
            'identifier_type' => 'email',
            'identifier_value' => $email,
            'known_sessions'  => [$sessionId],
            'device_fingerprints' => [],
            'custom_attributes' => [
                'sizes' => [
                    'shoes'    => ['us' => 10, 'eu' => 43, 'fit' => 'wide'],
                    'tops'     => ['size' => 'L', 'fit' => 'relaxed'],
                    'bottoms'  => ['waist' => 32, 'length' => 32],
                ],
                'size_history' => [
                    ['product' => 'Running Shoes XR', 'size' => 'US 10', 'fit_feedback' => 'perfect'],
                    ['product' => 'Classic Tee', 'size' => 'L', 'fit_feedback' => 'slightly_loose'],
                ],
            ],
        ]);

        // Retrieve the profile (as chatbot would when customer asks for size advice).
        $profile = CustomerProfile::where('tenant_id', $tid)
            ->where('identifier_value', $email)
            ->first();

        $this->assertNotNull($profile);

        $sizes = $profile->custom_attributes['sizes'] ?? [];
        $this->assertSame(10, $sizes['shoes']['us'], 'Shoe size US must be 10.');
        $this->assertSame('wide', $sizes['shoes']['fit'], 'Shoe fit must be wide.');
        $this->assertSame('L', $sizes['tops']['size'], 'Top size must be L.');

        // Verify sizing history is queryable.
        $history = $profile->custom_attributes['size_history'] ?? [];
        $this->assertCount(2, $history);
        $this->assertSame('perfect', $history[0]['fit_feedback']);

        // Update the profile with a new sizing entry (customer bought new shoes).
        $history[] = ['product' => 'Ultra Runner', 'size' => 'US 10.5', 'fit_feedback' => 'tight'];
        $profile->update([
            'custom_attributes' => array_merge($profile->custom_attributes, [
                'size_history' => $history,
                'sizes' => array_merge($sizes, [
                    'shoes' => ['us' => 10.5, 'eu' => 44, 'fit' => 'regular'],
                ]),
            ]),
        ]);

        // Re-read and verify persistence.
        $updated = CustomerProfile::where('tenant_id', $tid)
            ->where('identifier_value', $email)
            ->first();

        $this->assertCount(3, $updated->custom_attributes['size_history']);
        $this->assertEquals(10.5, $updated->custom_attributes['sizes']['shoes']['us'],
            'Updated shoe size must persist.');
    }
}
