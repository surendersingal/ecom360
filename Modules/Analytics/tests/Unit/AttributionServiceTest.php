<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests\Unit;

use Modules\Analytics\Models\TrackingEvent;
use Modules\Analytics\Services\AttributionService;
use Tests\TestCase;

/**
 * Unit tests for AttributionService.
 *
 * Covers:
 *  1. resolveConversionSource with no touchpoints.
 *  2. resolveConversionSource with exactly one touchpoint.
 *  3. resolveConversionSource with multiple touchpoints (first, last, assisted).
 *  4. resolveCrossSessionAttribution with empty session list.
 *  5. resolveCrossSessionAttribution across multiple sessions.
 *  6. Non-touchpoint events (page_view) are excluded.
 */
final class AttributionServiceTest extends TestCase
{
    private AttributionService $service;
    private const string TENANT = 'attr_test_tenant';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AttributionService::class);
        TrackingEvent::where('tenant_id', self::TENANT)->delete();
    }

    protected function tearDown(): void
    {
        TrackingEvent::where('tenant_id', self::TENANT)->delete();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function seedEvent(string $sessionId, string $eventType, string $url = 'https://example.com', array $metadata = [], ?string $createdAt = null): void
    {
        $event = TrackingEvent::create([
            'tenant_id'  => self::TENANT,
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'url'        => $url,
            'metadata'   => $metadata,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        if ($createdAt !== null) {
            $event->created_at = new \MongoDB\BSON\UTCDateTime(strtotime($createdAt) * 1000);
            $event->save();
        }
    }

    // ------------------------------------------------------------------
    //  1. No touchpoints
    // ------------------------------------------------------------------

    public function test_resolve_conversion_source_returns_null_when_no_touchpoints(): void
    {
        $result = $this->service->resolveConversionSource(self::TENANT, 'sess_empty');

        $this->assertNull($result['first_touch']);
        $this->assertNull($result['last_touch']);
        $this->assertSame([], $result['assisted_touches']);
        $this->assertSame(0, $result['touch_count']);
    }

    // ------------------------------------------------------------------
    //  2. Exactly one touchpoint
    // ------------------------------------------------------------------

    public function test_resolve_conversion_source_with_single_touchpoint(): void
    {
        $this->seedEvent('sess_single', 'product_view', 'https://example.com/product/1', ['product_id' => 'p1'], '2025-01-01 10:00:00');

        $result = $this->service->resolveConversionSource(self::TENANT, 'sess_single');

        $this->assertSame('product_view', $result['first_touch']['event_type']);
        $this->assertSame('product_view', $result['last_touch']['event_type']);
        $this->assertSame([], $result['assisted_touches']);
        $this->assertSame(1, $result['touch_count']);
    }

    // ------------------------------------------------------------------
    //  3. Multiple touchpoints — first, assisted, last
    // ------------------------------------------------------------------

    public function test_resolve_conversion_source_extracts_first_last_and_assisted(): void
    {
        // Seed events in chronological order
        $this->seedEvent('sess_multi', 'search', 'https://example.com/search?q=shoes', [], '2025-01-01 10:00:00');
        $this->seedEvent('sess_multi', 'product_view', 'https://example.com/product/1', [], '2025-01-01 10:05:00');
        $this->seedEvent('sess_multi', 'click', 'https://example.com/product/1/details', [], '2025-01-01 10:10:00');
        $this->seedEvent('sess_multi', 'add_to_cart', 'https://example.com/product/1', [], '2025-01-01 10:15:00');
        $this->seedEvent('sess_multi', 'begin_checkout', 'https://example.com/checkout', [], '2025-01-01 10:20:00');

        $result = $this->service->resolveConversionSource(self::TENANT, 'sess_multi');

        $this->assertSame(5, $result['touch_count']);
        $this->assertSame('search', $result['first_touch']['event_type']);
        $this->assertSame('begin_checkout', $result['last_touch']['event_type']);

        // 3 assisted touches between first and last
        $this->assertCount(3, $result['assisted_touches']);
        $assistedTypes = array_column($result['assisted_touches'], 'event_type');
        $this->assertSame(['product_view', 'click', 'add_to_cart'], $assistedTypes);
    }

    // ------------------------------------------------------------------
    //  4. Cross-session — empty session list
    // ------------------------------------------------------------------

    public function test_resolve_cross_session_with_empty_sessions(): void
    {
        $result = $this->service->resolveCrossSessionAttribution(self::TENANT, []);

        $this->assertNull($result['first_touch']);
        $this->assertNull($result['last_touch']);
        $this->assertSame(0, $result['total_sessions']);
        $this->assertSame(0, $result['total_touchpoints']);
    }

    // ------------------------------------------------------------------
    //  5. Cross-session attribution across multiple sessions
    // ------------------------------------------------------------------

    public function test_resolve_cross_session_attribution_across_sessions(): void
    {
        // Session A: user first visited via campaign
        $this->seedEvent('sess_a', 'campaign_event', 'https://example.com/landing', ['campaign' => 'summer_sale'], '2025-01-01 09:00:00');
        $this->seedEvent('sess_a', 'product_view', 'https://example.com/product/1', [], '2025-01-01 09:05:00');

        // Session B: user returned, searched, added to cart
        $this->seedEvent('sess_b', 'search', 'https://example.com/search?q=shoes', [], '2025-01-02 14:00:00');
        $this->seedEvent('sess_b', 'add_to_cart', 'https://example.com/product/1', [], '2025-01-02 14:10:00');
        $this->seedEvent('sess_b', 'begin_checkout', 'https://example.com/checkout', [], '2025-01-02 14:15:00');

        $result = $this->service->resolveCrossSessionAttribution(self::TENANT, ['sess_a', 'sess_b']);

        $this->assertSame(2, $result['total_sessions']);
        $this->assertSame(5, $result['total_touchpoints']);
        $this->assertSame('campaign_event', $result['first_touch']['event_type']);
        $this->assertSame('sess_a', $result['first_touch']['session_id']);
        $this->assertSame('begin_checkout', $result['last_touch']['event_type']);
        $this->assertSame('sess_b', $result['last_touch']['session_id']);
    }

    // ------------------------------------------------------------------
    //  6. page_view is NOT a touchpoint
    // ------------------------------------------------------------------

    public function test_page_view_is_excluded_from_touchpoints(): void
    {
        // Only page_views — no touchpoints
        $this->seedEvent('sess_pv', 'page_view', 'https://example.com/', [], '2025-01-01 10:00:00');
        $this->seedEvent('sess_pv', 'page_view', 'https://example.com/about', [], '2025-01-01 10:01:00');

        $result = $this->service->resolveConversionSource(self::TENANT, 'sess_pv');

        $this->assertSame(0, $result['touch_count']);
        $this->assertNull($result['first_touch']);
    }

    // ------------------------------------------------------------------
    //  7. Cross-session with no matching events
    // ------------------------------------------------------------------

    public function test_cross_session_with_sessions_but_no_touchpoints(): void
    {
        // Only page_views in these sessions
        $this->seedEvent('sess_x', 'page_view', 'https://example.com/', [], '2025-01-01 10:00:00');

        $result = $this->service->resolveCrossSessionAttribution(self::TENANT, ['sess_x', 'sess_nonexistent']);

        $this->assertSame(2, $result['total_sessions']);
        $this->assertSame(0, $result['total_touchpoints']);
        $this->assertNull($result['first_touch']);
        $this->assertNull($result['last_touch']);
    }
}
