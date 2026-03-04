<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Analytics\Services\AiAnalyticsService;
use Modules\Analytics\Services\EcommerceFunnelService;
use Modules\Analytics\Services\ProductAnalyticsService;
use Modules\Analytics\Services\RevenueAnalyticsService;
use Modules\Analytics\Services\SessionAnalyticsService;
use Modules\Analytics\Services\TrackingService;

final class DashboardController extends Controller
{
    public function __invoke(Request $request, string $tenantSlug): View
    {
        $tenant   = $request->attributes->get('tenant');
        $tenantId = (string) $tenant->id;

        try {
            $realtime   = app(AiAnalyticsService::class)->getRealTimeOverview($tenantId);
            $sessions   = app(SessionAnalyticsService::class)->getSessionMetrics($tenantId, '7d');
            $traffic    = app(TrackingService::class)->aggregateTraffic($tenantId, '7d');
            $funnel     = app(EcommerceFunnelService::class)->getFunnelMetrics($tenantId, '7d');
            $topProducts = app(ProductAnalyticsService::class)->getTopProducts($tenantId, '7d', 'purchase', 5);
            $revBySource = app(RevenueAnalyticsService::class)->getRevenueBySource($tenantId, '7d');
            $dailySessions = app(SessionAnalyticsService::class)->getDailySessionTrend($tenantId, '7d');
        } catch (\Throwable $e) {
            // Services may fail if MongoDB is unreachable — degrade gracefully
            $realtime = ['active_sessions' => 0, 'events_per_minute' => 0, 'top_pages' => [], 'geo_breakdown' => []];
            $sessions = ['total_sessions' => 0, 'bounce_rate' => 0];
            $traffic  = ['total_events' => 0, 'unique_sessions' => 0, 'event_type_breakdown' => []];
            $funnel   = ['stages' => [], 'overall_conversion_pct' => 0];
            $topProducts = [];
            $revBySource = [];
            $dailySessions = ['dates' => [], 'sessions' => []];
        }

        return view('tenant.dashboard', compact(
            'tenant', 'realtime', 'sessions', 'traffic', 'funnel',
            'topProducts', 'revBySource', 'dailySessions'
        ));
    }
}
