<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Str;
use Modules\Analytics\Models\TrackingEvent;
use Modules\Analytics\Services\EcommerceFunnelService;
use Tests\TestCase;

/**
 * Feature tests for EcommerceFunnelService.
 *
 * Validates the 4-stage ecommerce funnel:
 *   product_view → add_to_cart → begin_checkout → purchase
 *
 * Tests cover:
 *  - Perfect funnel (no drop-offs)
 *  - Progressive drop-off at each stage
 *  - Zero events (empty funnel)
 *  - Single stage only
 *  - Multiple sessions at first stage, only 1 at purchase
 *  - Date range filtering
 *  - Multi-tenant isolation
 */
final class EcommerceFunnelServiceTest extends TestCase
{
    private Tenant $tenant;
    private string $tenantId;
    private EcommerceFunnelService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name'      => 'Funnel Test Store',
            'slug'      => 'funnel-test-' . Str::random(6),
            'api_key'   => 'ek_' . Str::random(48),
            'is_active' => true,
        ]);
        $this->tenantId = (string) $this->tenant->id;
        $this->service = app(EcommerceFunnelService::class);

        TrackingEvent::where('tenant_id', $this->tenantId)->delete();
    }

    protected function tearDown(): void
    {
        TrackingEvent::where('tenant_id', $this->tenantId)->delete();
        $this->tenant->delete();
        parent::tearDown();
    }

    private function createFunnelEvent(string $sessionId, string $eventType): void
    {
        TrackingEvent::create([
            'tenant_id'  => $this->tenantId,
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'url'        => 'https://store.com/' . $eventType,
            'metadata'   => ['product_id' => 'prod_001', 'order_total' => 100.00],
            'custom_data'=> [],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Perfect Funnel (no drop-offs)
    // ─────────────────────────────────────────────────────────────────

    public function test_perfect_funnel_has_zero_dropoff(): void
    {
        // 3 sessions all go through the whole funnel.
        foreach (['s1', 's2', 's3'] as $session) {
            foreach (['product_view', 'add_to_cart', 'begin_checkout', 'purchase'] as $stage) {
                $this->createFunnelEvent($session, $stage);
            }
        }

        $result = $this->service->getFunnelMetrics($this->tenantId, '30d');

        $this->assertCount(4, $result['stages']);

        foreach ($result['stages'] as $index => $stage) {
            $this->assertSame(3, $stage['unique_sessions']);
            if ($index > 0) {
                $this->assertEqualsWithDelta(0.0, $stage['drop_off_pct'], 0.01);
            }
        }

        $this->assertEqualsWithDelta(100.0, $result['overall_conversion_pct'], 0.01);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Progressive Drop-off
    // ─────────────────────────────────────────────────────────────────

    public function test_progressive_dropoff_at_each_stage(): void
    {
        // 10 sessions view a product.
        for ($i = 1; $i <= 10; $i++) {
            $this->createFunnelEvent("s_{$i}", 'product_view');
        }

        // 6 sessions add to cart.
        for ($i = 1; $i <= 6; $i++) {
            $this->createFunnelEvent("s_{$i}", 'add_to_cart');
        }

        // 3 sessions begin checkout.
        for ($i = 1; $i <= 3; $i++) {
            $this->createFunnelEvent("s_{$i}", 'begin_checkout');
        }

        // 1 session purchases.
        $this->createFunnelEvent('s_1', 'purchase');

        $result = $this->service->getFunnelMetrics($this->tenantId, '30d');

        $this->assertSame(10, $result['stages'][0]['unique_sessions']); // product_view
        $this->assertSame(6, $result['stages'][1]['unique_sessions']);  // add_to_cart
        $this->assertSame(3, $result['stages'][2]['unique_sessions']);  // begin_checkout
        $this->assertSame(1, $result['stages'][3]['unique_sessions']);  // purchase

        // Drop-off: product_view → add_to_cart = 40%
        $this->assertEqualsWithDelta(40.0, $result['stages'][1]['drop_off_pct'], 0.1);

        // Drop-off: add_to_cart → begin_checkout = 50%
        $this->assertEqualsWithDelta(50.0, $result['stages'][2]['drop_off_pct'], 0.1);

        // Drop-off: begin_checkout → purchase = 66.67%
        $this->assertEqualsWithDelta(66.67, $result['stages'][3]['drop_off_pct'], 0.1);

        // Overall: 1/10 = 10%
        $this->assertEqualsWithDelta(10.0, $result['overall_conversion_pct'], 0.01);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Empty Funnel
    // ─────────────────────────────────────────────────────────────────

    public function test_empty_tenant_returns_zero_funnel(): void
    {
        $result = $this->service->getFunnelMetrics($this->tenantId, '30d');

        $this->assertCount(4, $result['stages']);

        foreach ($result['stages'] as $stage) {
            $this->assertSame(0, $stage['unique_sessions']);
        }

        $this->assertEqualsWithDelta(0.0, $result['overall_conversion_pct'], 0.01);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Only Product Views (no conversions)
    // ─────────────────────────────────────────────────────────────────

    public function test_only_product_views_no_conversions(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createFunnelEvent("s_{$i}", 'product_view');
        }

        $result = $this->service->getFunnelMetrics($this->tenantId, '30d');

        $this->assertSame(5, $result['stages'][0]['unique_sessions']);
        $this->assertSame(0, $result['stages'][1]['unique_sessions']);
        $this->assertSame(0, $result['stages'][2]['unique_sessions']);
        $this->assertSame(0, $result['stages'][3]['unique_sessions']);
        $this->assertEqualsWithDelta(0.0, $result['overall_conversion_pct'], 0.01);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Same Session Multiple Events of Same Type (deduplication)
    // ─────────────────────────────────────────────────────────────────

    public function test_duplicate_events_in_same_session_are_deduplicated(): void
    {
        // Session views 3 products but should count as 1 unique session.
        $this->createFunnelEvent('s_dup', 'product_view');
        $this->createFunnelEvent('s_dup', 'product_view');
        $this->createFunnelEvent('s_dup', 'product_view');
        $this->createFunnelEvent('s_dup', 'add_to_cart');

        $result = $this->service->getFunnelMetrics($this->tenantId, '30d');

        // Only 1 unique session at product_view stage.
        $this->assertSame(1, $result['stages'][0]['unique_sessions']);
        $this->assertSame(1, $result['stages'][1]['unique_sessions']);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Date Range Filtering
    // ─────────────────────────────────────────────────────────────────

    public function test_date_range_excludes_old_events(): void
    {
        // Old event (60 days ago) — disable timestamps to preserve created_at.
        $oldEvent = new TrackingEvent([
            'tenant_id'  => $this->tenantId,
            'session_id' => 's_old',
            'event_type' => 'product_view',
            'url'        => 'https://store.com/product',
            'metadata'   => [],
            'custom_data'=> [],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);
        $oldEvent->timestamps = false;
        $oldEvent->created_at = now()->subDays(60);
        $oldEvent->updated_at = now()->subDays(60);
        $oldEvent->save();

        // Recent event.
        $this->createFunnelEvent('s_recent', 'product_view');

        $result7d = $this->service->getFunnelMetrics($this->tenantId, '7d');
        $this->assertSame(1, $result7d['stages'][0]['unique_sessions']);

        $result90d = $this->service->getFunnelMetrics($this->tenantId, '90d');
        $this->assertSame(2, $result90d['stages'][0]['unique_sessions']);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Multi-Tenant Isolation
    // ─────────────────────────────────────────────────────────────────

    public function test_funnel_is_isolated_between_tenants(): void
    {
        $tenant2 = Tenant::create([
            'name'      => 'Other Funnel Store',
            'slug'      => 'other-funnel-' . Str::random(6),
            'api_key'   => 'ek_' . Str::random(48),
            'is_active' => true,
        ]);

        $this->createFunnelEvent('s_mine', 'product_view');

        // Event for other tenant.
        TrackingEvent::create([
            'tenant_id'  => (string) $tenant2->id,
            'session_id' => 's_theirs',
            'event_type' => 'product_view',
            'url'        => 'https://other-store.com/product',
            'metadata'   => [],
            'custom_data'=> [],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $result = $this->service->getFunnelMetrics($this->tenantId, '30d');
        $this->assertSame(1, $result['stages'][0]['unique_sessions']);

        // Cleanup.
        TrackingEvent::where('tenant_id', (string) $tenant2->id)->delete();
        $tenant2->delete();
    }
}
