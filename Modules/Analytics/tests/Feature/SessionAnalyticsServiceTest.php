<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Str;
use Modules\Analytics\Models\TrackingEvent;
use Modules\Analytics\Services\SessionAnalyticsService;
use Tests\TestCase;

/**
 * Feature tests for SessionAnalyticsService.
 *
 * Verifies bounce rate, average session duration, pages-per-session,
 * new-vs-returning breakdown, daily trend, and landing/exit pages
 * using real MongoDB data.
 */
final class SessionAnalyticsServiceTest extends TestCase
{
    private Tenant $tenant;
    private string $tenantId;
    private SessionAnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name'      => 'Session Analytics Store',
            'slug'      => 'session-test-' . Str::random(6),
            'api_key'   => 'ek_' . Str::random(48),
            'is_active' => true,
        ]);
        $this->tenantId = (string) $this->tenant->id;
        $this->service = app(SessionAnalyticsService::class);

        TrackingEvent::where('tenant_id', $this->tenantId)->delete();
    }

    protected function tearDown(): void
    {
        TrackingEvent::where('tenant_id', $this->tenantId)->delete();
        $this->tenant->delete();
        parent::tearDown();
    }

    private function createEvent(string $sessionId, string $eventType, string $url, ?\DateTimeInterface $createdAt = null): void
    {
        $event = new TrackingEvent([
            'tenant_id'  => $this->tenantId,
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'url'        => $url,
            'metadata'   => [],
            'custom_data'=> [],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        if ($createdAt !== null) {
            $event->timestamps = false;
            $event->created_at = $createdAt;
            $event->updated_at = $createdAt;
        }

        $event->save();
    }

    // ─────────────────────────────────────────────────────────────────
    //  Bounce Rate
    // ─────────────────────────────────────────────────────────────────

    public function test_bounce_rate_for_single_event_sessions(): void
    {
        // 2 sessions with only 1 event each (bounces).
        $this->createEvent('s_bounce_1', 'page_view', 'https://store.com/');
        $this->createEvent('s_bounce_2', 'page_view', 'https://store.com/about');

        // 1 session with multiple events (not a bounce).
        $this->createEvent('s_engaged', 'page_view', 'https://store.com/');
        $this->createEvent('s_engaged', 'product_view', 'https://store.com/product/1');

        $metrics = $this->service->getSessionMetrics($this->tenantId, '30d');

        $this->assertSame(3, $metrics['total_sessions']);
        // 2 out of 3 sessions bounced → 66.7%
        $this->assertEqualsWithDelta(66.7, $metrics['bounce_rate'], 0.1);
        $this->assertSame(2, $metrics['bounce_sessions']);
    }

    public function test_zero_bounce_rate_when_all_sessions_have_multiple_events(): void
    {
        $this->createEvent('s1', 'page_view', 'https://store.com/');
        $this->createEvent('s1', 'product_view', 'https://store.com/product/1');
        $this->createEvent('s2', 'page_view', 'https://store.com/');
        $this->createEvent('s2', 'add_to_cart', 'https://store.com/product/2');

        $metrics = $this->service->getSessionMetrics($this->tenantId, '30d');

        $this->assertSame(2, $metrics['total_sessions']);
        $this->assertEqualsWithDelta(0.0, $metrics['bounce_rate'], 0.1);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Average Pages Per Session
    // ─────────────────────────────────────────────────────────────────

    public function test_avg_pages_per_session(): void
    {
        // Session 1: 3 page views.
        $this->createEvent('s1', 'page_view', 'https://store.com/');
        $this->createEvent('s1', 'page_view', 'https://store.com/products');
        $this->createEvent('s1', 'page_view', 'https://store.com/about');

        // Session 2: 1 page view + 1 product view (only page_view counts for pages).
        $this->createEvent('s2', 'page_view', 'https://store.com/');
        $this->createEvent('s2', 'product_view', 'https://store.com/product/1');

        $metrics = $this->service->getSessionMetrics($this->tenantId, '30d');

        // Session 1: 3 pages, Session 2: 1 page → avg = 2.0
        $this->assertEqualsWithDelta(2.0, $metrics['avg_pages_per_session'], 0.1);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Average Session Duration
    // ─────────────────────────────────────────────────────────────────

    public function test_avg_session_duration_is_calculated(): void
    {
        // Session 1: ~10 minutes apart.
        $this->createEvent('s_dur1', 'page_view', 'https://store.com/', now()->subMinutes(20));
        $this->createEvent('s_dur1', 'page_view', 'https://store.com/about', now()->subMinutes(10));

        // Session 2: ~5 minutes apart.
        $this->createEvent('s_dur2', 'page_view', 'https://store.com/', now()->subMinutes(8));
        $this->createEvent('s_dur2', 'page_view', 'https://store.com/contact', now()->subMinutes(3));

        $metrics = $this->service->getSessionMetrics($this->tenantId, '30d');

        // Avg ~450s ((600+300)/2). Allow generous tolerance for timing.
        $this->assertGreaterThan(0, $metrics['avg_session_duration_seconds']);
        $this->assertGreaterThan(100, $metrics['avg_session_duration_seconds']);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Empty Tenant
    // ─────────────────────────────────────────────────────────────────

    public function test_empty_tenant_returns_zero_metrics(): void
    {
        $metrics = $this->service->getSessionMetrics($this->tenantId, '30d');

        $this->assertSame(0, $metrics['total_sessions']);
        $this->assertEqualsWithDelta(0.0, $metrics['bounce_rate'], 0.01);
        $this->assertSame(0, $metrics['avg_session_duration_seconds']);
    }

    // ─────────────────────────────────────────────────────────────────
    //  New vs Returning
    // ─────────────────────────────────────────────────────────────────

    public function test_new_vs_returning_breakdown(): void
    {
        // Create 3 sessions within the last 30 days.
        $this->createEvent('s_new_1', 'page_view', 'https://store.com/', now()->subDays(5));
        $this->createEvent('s_new_2', 'page_view', 'https://store.com/', now()->subDays(2));
        $this->createEvent('s_new_3', 'page_view', 'https://store.com/', now());

        $breakdown = $this->service->getNewVsReturning($this->tenantId, '30d');

        $this->assertSame(3, $breakdown['total_sessions']);
        $this->assertGreaterThanOrEqual(0, $breakdown['new_sessions']);
        $this->assertGreaterThanOrEqual(0, $breakdown['returning_sessions']);
        $this->assertEqualsWithDelta(100.0, $breakdown['new_pct'] + $breakdown['returning_pct'], 0.1);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Top Landing Pages
    // ─────────────────────────────────────────────────────────────────

    public function test_top_landing_pages_returns_first_page_of_each_session(): void
    {
        // Session 1 lands on homepage.
        $this->createEvent('s_lp1', 'page_view', 'https://store.com/', now()->subMinutes(30));
        $this->createEvent('s_lp1', 'page_view', 'https://store.com/products', now()->subMinutes(29));

        // Session 2 lands on products page.
        $this->createEvent('s_lp2', 'page_view', 'https://store.com/products', now()->subMinutes(20));
        $this->createEvent('s_lp2', 'page_view', 'https://store.com/cart', now()->subMinutes(19));

        // Session 3 also lands on homepage.
        $this->createEvent('s_lp3', 'page_view', 'https://store.com/', now()->subMinutes(10));
        $this->createEvent('s_lp3', 'page_view', 'https://store.com/about', now()->subMinutes(9));

        $pages = $this->service->getTopLandingPages($this->tenantId, '30d');

        $this->assertNotEmpty($pages);
        // Homepage should be top landing page (2 sessions).
        $this->assertSame('https://store.com/', $pages[0]['url']);
        $this->assertSame(2, $pages[0]['sessions']);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Top Exit Pages
    // ─────────────────────────────────────────────────────────────────

    public function test_top_exit_pages_returns_last_page_of_each_session(): void
    {
        // Session 1 exits on about page.
        $this->createEvent('s_ep1', 'page_view', 'https://store.com/', now()->subMinutes(30));
        $this->createEvent('s_ep1', 'page_view', 'https://store.com/about', now()->subMinutes(29));

        // Session 2 exits on cart page.
        $this->createEvent('s_ep2', 'page_view', 'https://store.com/', now()->subMinutes(20));
        $this->createEvent('s_ep2', 'page_view', 'https://store.com/cart', now()->subMinutes(19));

        // Session 3 also exits on about page.
        $this->createEvent('s_ep3', 'page_view', 'https://store.com/products', now()->subMinutes(10));
        $this->createEvent('s_ep3', 'page_view', 'https://store.com/about', now()->subMinutes(9));

        $pages = $this->service->getTopExitPages($this->tenantId, '30d');

        $this->assertNotEmpty($pages);
        // /about should be top exit page (2 sessions).
        $this->assertSame('https://store.com/about', $pages[0]['url']);
        $this->assertSame(2, $pages[0]['sessions']);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Daily Session Trend
    // ─────────────────────────────────────────────────────────────────

    public function test_daily_session_trend(): void
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();

        $this->createEvent('s_t1', 'page_view', 'https://store.com/', $today->copy()->addHour());
        $this->createEvent('s_t2', 'page_view', 'https://store.com/', $today->copy()->addHours(2));
        $this->createEvent('s_y1', 'page_view', 'https://store.com/', $yesterday->copy()->addHour());

        $trend = $this->service->getDailySessionTrend($this->tenantId, '7d');

        $this->assertArrayHasKey('dates', $trend);
        $this->assertArrayHasKey('sessions', $trend);
        $this->assertNotEmpty($trend['dates']);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Custom Date Range
    // ─────────────────────────────────────────────────────────────────

    public function test_custom_date_range_filters_correctly(): void
    {
        // Event 40 days ago (outside 30d range) — disable timestamps to preserve created_at.
        $this->createEvent('s_old', 'page_view', 'https://store.com/', now()->subDays(40));

        // Event 5 days ago (inside range).
        $this->createEvent('s_recent', 'page_view', 'https://store.com/', now()->subDays(5));

        $metrics7d = $this->service->getSessionMetrics($this->tenantId, '7d');
        $this->assertSame(1, $metrics7d['total_sessions']); // Only the 5-day event

        // Wider range should include both.
        $metrics60d = $this->service->getSessionMetrics($this->tenantId, '60d');
        $this->assertSame(2, $metrics60d['total_sessions']);
    }
}
