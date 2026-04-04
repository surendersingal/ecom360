<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Analytics\Services\CampaignAnalyticsService;
use Modules\Analytics\Services\CohortAnalysisService;
use Modules\Analytics\Services\EcommerceFunnelService;
use Modules\Analytics\Services\GeographicAnalyticsService;
use Modules\Analytics\Services\ProductAnalyticsService;
use Modules\Analytics\Services\RevenueAnalyticsService;
use Modules\Analytics\Services\SessionAnalyticsService;
use Modules\Analytics\Services\TrackingService;

/**
 * Enterprise Analytics REST API — comprehensive read endpoints for all analytics data.
 *
 * All endpoints are tenant-scoped via the authenticated user's tenant_id.
 * All accept optional `date_range` query param (7d, 30d, 90d, ytd, or YYYY-MM-DD|YYYY-MM-DD).
 */
final class AnalyticsApiController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/analytics/overview
     * Dashboard overview — key KPIs across all analytics modules.
     */
    public function overview(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $range = $request->query('date_range', '30d');

        $tracking = app(TrackingService::class);
        $revenue = app(RevenueAnalyticsService::class);
        $session = app(SessionAnalyticsService::class);

        $traffic = $tracking->aggregateTraffic($tenantId, $range);
        $revenueComparison = $revenue->getRevenueComparison($tenantId, $range);
        $sessionMetrics = $session->getSessionMetrics($tenantId, $range);

        return $this->successResponse([
            'traffic' => $traffic,
            'revenue' => $revenueComparison,
            'sessions' => $sessionMetrics,
        ]);
    }

    /**
     * GET /api/v1/analytics/traffic
     * Traffic statistics — page views, unique sessions, event breakdowns.
     */
    public function traffic(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $range = $request->query('date_range', '30d');

        $data = app(TrackingService::class)->aggregateTraffic($tenantId, $range);

        return $this->successResponse($data);
    }

    /**
     * GET /api/v1/analytics/revenue
     * Revenue analytics — daily revenue, revenue by source, hourly patterns.
     */
    public function revenue(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $range = $request->query('date_range', '30d');
        $service = app(RevenueAnalyticsService::class);

        return $this->successResponse([
            'daily' => $service->getDailyRevenue($tenantId, $range),
            'by_source' => $service->getRevenueBySource($tenantId, $range),
            'hourly_pattern' => $service->getHourlyRevenuePattern($tenantId, $range),
            'comparison' => $service->getRevenueComparison($tenantId, $range),
        ]);
    }

    /**
     * GET /api/v1/analytics/products
     * Product analytics — top products, product performance, frequently bought together.
     */
    public function products(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $range = $request->query('date_range', '30d');
        $limit = (int) $request->query('limit', '20');
        $service = app(ProductAnalyticsService::class);

        return $this->successResponse([
            'top_by_purchases' => $service->getTopProducts($tenantId, $range, 'purchase', $limit),
            'top_by_views' => $service->getTopProducts($tenantId, $range, 'product_view', $limit),
            'performance' => $service->getProductPerformance($tenantId, $range, $limit),
            'frequently_bought_together' => $service->getFrequentlyBoughtTogether($tenantId, $range, 10),
            'cart_abandonment' => $service->getCartAbandonmentProducts($tenantId, $range, $limit),
        ]);
    }

    /**
     * GET /api/v1/analytics/sessions
     * Session analytics — metrics, trends, new vs returning, landing/exit pages.
     */
    public function sessions(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $range = $request->query('date_range', '30d');
        $service = app(SessionAnalyticsService::class);

        return $this->successResponse([
            'metrics' => $service->getSessionMetrics($tenantId, $range),
            'daily_trend' => $service->getDailySessionTrend($tenantId, $range),
            'new_vs_returning' => $service->getNewVsReturning($tenantId, $range),
            'top_landing_pages' => $service->getTopLandingPages($tenantId, $range, 20),
            'top_exit_pages' => $service->getTopExitPages($tenantId, $range, 20),
        ]);
    }

    /**
     * GET /api/v1/analytics/funnel
     * Conversion funnel — stage-by-stage breakdown with drop-off percentages.
     */
    public function funnel(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $range = $request->query('date_range', '30d');

        $data = app(EcommerceFunnelService::class)->getFunnelMetrics($tenantId, $range);

        return $this->successResponse($data);
    }

    /**
     * GET /api/v1/analytics/customers
     * Customer analytics — RFM segments, profiles summary.
     */
    public function customers(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $range = $request->query('date_range', '30d');
        $cohort = app(CohortAnalysisService::class);

        return $this->successResponse([
            'clv_by_segment' => $cohort->getClvBySegment($tenantId, $range),
            'repeat_purchase' => $cohort->getRepeatPurchaseRate($tenantId, $range),
            'retention_cohorts' => $cohort->getRetentionCohorts($tenantId, 6),
        ]);
    }

    /**
     * GET /api/v1/analytics/geographic
     * Geographic analytics — visitors by country/city, device/browser breakdown.
     */
    public function geographic(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $range = $request->query('date_range', '30d');
        $service = app(GeographicAnalyticsService::class);

        $deviceData = $service->getDeviceBreakdown($tenantId, $range);

        // Flatten devices dict → array for blade consumption
        $deviceArray = [];
        foreach (($deviceData['devices'] ?? []) as $type => $count) {
            $deviceArray[] = ['device' => ucfirst($type), 'count' => $count];
        }
        usort($deviceArray, fn($a, $b) => $b['count'] <=> $a['count']);

        // Flatten browsers dict → array
        $browserArray = [];
        foreach (($deviceData['browsers'] ?? []) as $name => $count) {
            $browserArray[] = ['browser' => $name, 'count' => $count];
        }

        // OS breakdown from user_agent parsing (already done in service)
        $osArray = [];
        foreach (($deviceData['operating_systems'] ?? []) as $name => $count) {
            $osArray[] = ['os' => $name, 'count' => $count];
        }

        // Resolution breakdown
        $resArray = [];
        foreach (($deviceData['resolutions'] ?? []) as $name => $count) {
            $resArray[] = ['resolution' => $name, 'count' => $count];
        }

        return $this->successResponse([
            'by_country' => $service->getVisitorsByCountry($tenantId, $range),
            'by_city' => $service->getVisitorsByCity($tenantId, $range, 20),
            'devices' => $deviceData,
            'device_breakdown' => $deviceArray,
            'browser_breakdown' => $browserArray,
            'os_breakdown' => $osArray,
            'resolution_breakdown' => $resArray,
            'traffic_by_hour' => $service->getTrafficByHour($tenantId, $range),
        ]);
    }

    /**
     * GET /api/v1/analytics/cohorts
     * Cohort analysis — retention matrix, CLV by segment.
     */
    public function cohorts(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $months = (int) $request->query('months', '6');
        $range = $request->query('date_range', '90d');
        $service = app(CohortAnalysisService::class);

        return $this->successResponse([
            'retention' => $service->getRetentionCohorts($tenantId, $months),
            'repeat_purchase' => $service->getRepeatPurchaseRate($tenantId, $range),
            'clv_by_segment' => $service->getClvBySegment($tenantId, $range),
        ]);
    }

    /**
     * GET /api/v1/analytics/campaigns
     * Campaign analytics — UTM breakdown, channel attribution, referrer sources.
     */
    public function campaigns(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $range = $request->query('date_range', '30d');
        $service = app(CampaignAnalyticsService::class);

        return $this->successResponse([
            'performance' => $service->getCampaignPerformance($tenantId, $range),
            'utm_breakdown' => $service->getUtmBreakdown($tenantId, $range),
            'channel_attribution' => $service->getChannelAttribution($tenantId, $range),
            'referrer_sources' => $service->getReferrerSources($tenantId, $range, 20),
        ]);
    }

    /**
     * GET /api/v1/analytics/realtime
     * Real-time metrics — active sessions, events per minute.
     */
    public function realtime(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $mongo = app('db')->connection('mongodb');
        $collection = $mongo->getCollection('tracking_events');

        $now = now();

        $active5min = count(iterator_to_array($collection->aggregate([
            ['$match' => [
                'tenant_id' => $tenantId,
                'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subMinutes(5)->getTimestamp() * 1000)],
            ]],
            ['$group' => ['_id' => '$session_id']],
        ], ['maxTimeMS' => 30000])));

        $active15min = count(iterator_to_array($collection->aggregate([
            ['$match' => [
                'tenant_id' => $tenantId,
                'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subMinutes(15)->getTimestamp() * 1000)],
            ]],
            ['$group' => ['_id' => '$session_id']],
        ], ['maxTimeMS' => 30000])));

        $eventsLastHour = $collection->countDocuments([
            'tenant_id' => $tenantId,
            'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subHour()->getTimestamp() * 1000)],
        ]);

        $purchasesLastHour = $collection->countDocuments([
            'tenant_id' => $tenantId,
            'event_type' => 'purchase',
            'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subHour()->getTimestamp() * 1000)],
        ]);

        // Events by type in last 15 minutes
        $recentByType = iterator_to_array($collection->aggregate([
            ['$match' => [
                'tenant_id' => $tenantId,
                'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subMinutes(15)->getTimestamp() * 1000)],
            ]],
            ['$group' => ['_id' => '$event_type', 'count' => ['$sum' => 1]]],
            ['$sort' => ['count' => -1]],
        ], ['maxTimeMS' => 30000]));

        return $this->successResponse([
            'active_sessions_5min' => $active5min,
            'active_sessions_15min' => $active15min,
            'events_last_hour' => (int) $eventsLastHour,
            'purchases_last_hour' => (int) $purchasesLastHour,
            'events_per_minute' => round($eventsLastHour / 60, 2),
            'recent_event_types' => $recentByType,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/analytics/export
     * Export analytics data — returns raw events in paginated format.
     */
    public function export(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $range = $request->query('date_range', '7d');
        $eventType = $request->query('event_type');
        $page = (int) $request->query('page', '1');
        $perPage = min((int) $request->query('per_page', '100'), 1000);
        $format = $request->query('format', 'json');

        $mongo = app('db')->connection('mongodb');
        $collection = $mongo->getCollection('tracking_events');

        [$dateFrom, $dateTo] = $this->parseDateRange($range);

        $match = [
            'tenant_id' => $tenantId,
            'created_at' => [
                '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
                '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
            ],
        ];

        if ($eventType) {
            $match['event_type'] = $eventType;
        }

        $totalCount = $collection->countDocuments($match);

        $events = iterator_to_array($collection->aggregate([
            ['$match' => $match],
            ['$sort' => ['created_at' => -1]],
            ['$skip' => ($page - 1) * $perPage],
            ['$limit' => $perPage],
            ['$project' => [
                '_id' => ['$toString' => '$_id'],
                'session_id' => 1,
                'event_type' => 1,
                'url' => 1,
                'metadata' => 1,
                'custom_data' => 1,
                'ip_address' => 1,
                'user_agent' => 1,
                'created_at' => 1,
            ]],
        ], ['maxTimeMS' => 30000]));

        return $this->successResponse([
            'events' => array_values($events),
            'pagination' => [
                'total' => (int) $totalCount,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($totalCount / $perPage),
                'has_more' => ($page * $perPage) < $totalCount,
            ],
            'filters' => [
                'date_range' => $range,
                'event_type' => $eventType,
            ],
        ]);
    }

    /**
     * POST /api/v1/analytics/events/custom
     * Track a custom event — validates against custom event definitions.
     */
    public function trackCustomEvent(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();

        $validated = $request->validate([
            'event_key' => ['required', 'string', 'max:100'],
            'session_id' => ['required', 'string', 'max:128'],
            'url' => ['required', 'string', 'url', 'max:2048'],
            'metadata' => ['sometimes', 'array'],
            'custom_data' => ['nullable', 'array'],
            'ip_address' => ['sometimes', 'string', 'ip'],
            'user_agent' => ['sometimes', 'string', 'max:512'],
            'device_fingerprint' => ['nullable', 'string', 'max:128'],
            'customer_identifier' => ['nullable', 'array'],
            'customer_identifier.type' => ['required_with:customer_identifier', 'string', 'in:email,phone'],
            'customer_identifier.value' => ['required_with:customer_identifier', 'string', 'max:255'],
        ]);

        // Verify custom event definition exists
        $definition = \Modules\Analytics\Models\CustomEventDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('event_key', $validated['event_key'])
            ->where('is_active', true)
            ->first();

        if ($definition === null) {
            return $this->errorResponse(
                "Custom event '{$validated['event_key']}' is not defined or is inactive for this tenant.",
                422
            );
        }

        // Track via TrackingService
        $payload = [
            'session_id' => $validated['session_id'],
            'event_type' => $validated['event_key'],
            'url' => $validated['url'],
            'metadata' => $validated['metadata'] ?? [],
            'custom_data' => $validated['custom_data'] ?? [],
            'ip_address' => $validated['ip_address'] ?? $request->ip(),
            'user_agent' => $validated['user_agent'] ?? $request->userAgent() ?? '',
            'device_fingerprint' => $validated['device_fingerprint'] ?? null,
            'customer_identifier' => $validated['customer_identifier'] ?? null,
        ];

        $trackingEvent = app(TrackingService::class)->logEvent($tenantId, $payload);

        // Increment event count on definition
        $definition->increment('event_count');

        return $this->successResponse([
            'tracking_event_id' => (string) $trackingEvent->_id,
            'event_key' => $validated['event_key'],
            'session_id' => $trackingEvent->session_id,
        ], 'Custom event tracked successfully.', 201);
    }

    /**
     * GET /api/v1/analytics/events/custom/definitions
     * List all custom event definitions for the tenant.
     */
    public function customEventDefinitions(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();

        $definitions = \Modules\Analytics\Models\CustomEventDefinition::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('event_key')
            ->get()
            ->map(fn ($def) => [
                'id' => $def->id,
                'event_key' => $def->event_key,
                'display_name' => $def->display_name,
                'description' => $def->description,
                'schema' => $def->schema,
                'is_active' => $def->is_active,
                'event_count' => $def->event_count,
                'created_at' => $def->created_at?->toIso8601String(),
                'updated_at' => $def->updated_at?->toIso8601String(),
            ])
            ->toArray();

        return $this->successResponse([
            'definitions' => $definitions,
            'total' => count($definitions),
        ]);
    }

    /**
     * POST /api/v1/analytics/events/custom/definitions
     * Create a new custom event definition.
     */
    public function createCustomEventDefinition(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();

        $validated = $request->validate([
            'event_key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'schema' => ['nullable', 'array'],
            'schema.*.field_name' => ['required', 'string', 'max:100'],
            'schema.*.field_type' => ['required', 'string', 'in:string,number,boolean,array,object'],
            'schema.*.required' => ['sometimes', 'boolean'],
            'schema.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        // Check uniqueness
        $exists = \Modules\Analytics\Models\CustomEventDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('event_key', $validated['event_key'])
            ->exists();

        if ($exists) {
            return $this->errorResponse(
                "Event key '{$validated['event_key']}' already exists for this tenant.",
                409
            );
        }

        $definition = \Modules\Analytics\Models\CustomEventDefinition::create([
            'tenant_id' => $tenantId,
            'event_key' => $validated['event_key'],
            'display_name' => $validated['display_name'],
            'description' => $validated['description'] ?? null,
            'schema' => $validated['schema'] ?? null,
            'is_active' => true,
            'event_count' => 0,
        ]);

        return $this->successResponse([
            'id' => $definition->id,
            'event_key' => $definition->event_key,
            'display_name' => $definition->display_name,
        ], 'Custom event definition created.', 201);
    }

    /**
     * GET /api/v1/analytics/page-visits
     * Page-level analytics — top pages, landing pages, exit pages.
     */
    public function pageVisits(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $range = $request->query('date_range', '30d');
        $limit = (int) $request->query('limit', '20');
        $session = app(SessionAnalyticsService::class);

        return $this->successResponse([
            'top_landing_pages' => $session->getTopLandingPages($tenantId, $range, $limit),
            'top_exit_pages' => $session->getTopExitPages($tenantId, $range, $limit),
        ]);
    }

    /**
     * GET /api/v1/analytics/categories
     * Category analytics — views, carts, purchases by category.
     */
    public function categories(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $range = $request->query('date_range', '30d');

        [$dateFrom, $dateTo] = $this->parseDateRange($range);
        $mongo = app('db')->connection('mongodb');
        $collection = $mongo->getCollection('tracking_events');
        $dateMatch = [
            '$gte' => new \MongoDB\BSON\UTCDateTime($dateFrom->getTimestamp() * 1000),
            '$lte' => new \MongoDB\BSON\UTCDateTime($dateTo->getTimestamp() * 1000),
        ];

        $categoryViews = iterator_to_array($collection->aggregate([
            ['$match' => [
                'tenant_id' => $tenantId,
                'event_type' => ['$in' => ['product_view', 'page_view']],
                'metadata.category' => ['$exists' => true, '$ne' => null],
                'created_at' => $dateMatch,
            ]],
            ['$group' => [
                '_id' => '$metadata.category',
                'views' => ['$sum' => 1],
                'unique_sessions' => ['$addToSet' => '$session_id'],
            ]],
            ['$project' => [
                'category' => '$_id',
                'views' => 1,
                'unique_visitors' => ['$size' => '$unique_sessions'],
            ]],
            ['$sort' => ['views' => -1]],
            ['$limit' => 30],
        ], ['maxTimeMS' => 30000]));

        $categoryPurchases = iterator_to_array($collection->aggregate([
            ['$match' => [
                'tenant_id' => $tenantId,
                'event_type' => 'purchase',
                'metadata.category' => ['$exists' => true, '$ne' => null],
                'created_at' => $dateMatch,
            ]],
            ['$group' => [
                '_id' => '$metadata.category',
                'purchases' => ['$sum' => 1],
                'revenue' => ['$sum' => '$metadata.order_total'],
            ]],
            ['$sort' => ['purchases' => -1]],
        ], ['maxTimeMS' => 30000]));

        return $this->successResponse([
            'category_views' => $categoryViews,
            'category_purchases' => $categoryPurchases,
        ]);
    }

    // ────────── MATOMO-PARITY ENDPOINTS ──────────

    /**
     * All Pages – aggregate page_view events by URL, Matomo "Behaviour > Pages" equivalent
     */
    public function allPages(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        [$from, $to] = $this->parseDateRange($request->input('range', '30d'));
        $dateMatch = ['$gte' => new \MongoDB\BSON\UTCDateTime($from->getTimestamp() * 1000), '$lte' => new \MongoDB\BSON\UTCDateTime($to->getTimestamp() * 1000)];

        $collection = \DB::connection('mongodb')->getCollection('tracking_events');

        // ── Step 1: Compute per-session landing/exit/duration for bounce & exit rate ──
        $sessionStats = iterator_to_array($collection->aggregate([
            ['$match' => ['tenant_id' => $tenantId, 'event_type' => ['$in' => ['page_view', 'product_view']], 'created_at' => $dateMatch]],
            ['$sort' => ['created_at' => 1]],
            ['$group' => [
                '_id' => '$session_id',
                'pages' => ['$push' => ['$ifNull' => ['$metadata.url', '$url']]],
                'first_time' => ['$first' => '$created_at'],
                'last_time' => ['$last' => '$created_at'],
                'event_count' => ['$sum' => 1],
            ]],
        ], ['maxTimeMS' => 30000]));

        // Build per-URL bounce/exit/time maps
        $urlBounce = [];
        $urlExit = [];
        $urlTime = [];
        $urlTotal = [];

        foreach ($sessionStats as $sess) {
            $pages = $sess['pages'] ?? [];
            if (empty($pages)) continue;

            $isBounce = count($pages) === 1;
            $landing = $pages[0] ?? '';
            $exit = end($pages);

            // Session duration shared equally across pages
            $first = $sess['first_time'];
            $last = $sess['last_time'];
            $durMs = 0;
            if ($first instanceof \MongoDB\BSON\UTCDateTime && $last instanceof \MongoDB\BSON\UTCDateTime) {
                $durMs = (int) ((string) $last) - (int) ((string) $first);
            }
            $avgTimePerPage = count($pages) > 0 ? ($durMs / 1000) / count($pages) : 0;

            foreach ($pages as $url) {
                if ($url instanceof \MongoDB\Model\BSONArray) {
                    $url = $url[0] ?? '';
                }
                if (!is_string($url)) {
                    $url = is_scalar($url) ? (string) $url : '';
                }
                if ($url === '') continue;
                $urlTotal[$url] = ($urlTotal[$url] ?? 0) + 1;
                $urlTime[$url] = ($urlTime[$url] ?? 0) + $avgTimePerPage;
            }

            if ($landing instanceof \MongoDB\Model\BSONArray) { $landing = $landing[0] ?? ''; }
            if (!is_string($landing)) { $landing = is_scalar($landing) ? (string) $landing : ''; }
            if ($exit instanceof \MongoDB\Model\BSONArray) { $exit = $exit[0] ?? ''; }
            if (!is_string($exit)) { $exit = is_scalar($exit) ? (string) $exit : ''; }

            if ($isBounce && $landing) {
                $urlBounce[$landing] = ($urlBounce[$landing] ?? 0) + 1;
            }
            if ($exit) {
                $urlExit[$exit] = ($urlExit[$exit] ?? 0) + 1;
            }
        }

        // ── Step 2: Page view counts per URL (includes product_view) ──
        $pages = iterator_to_array($collection->aggregate([
            ['$match' => ['tenant_id' => $tenantId, 'event_type' => ['$in' => ['page_view', 'product_view']], 'created_at' => $dateMatch]],
            ['$group' => [
                '_id' => ['$ifNull' => ['$metadata.url', '$url']],
                'pageviews' => ['$sum' => 1],
                'unique_sessions' => ['$addToSet' => '$session_id'],
            ]],
            ['$project' => [
                '_id' => 0,
                'url' => '$_id',
                'pageviews' => 1,
                'unique' => ['$size' => '$unique_sessions'],
            ]],
            ['$sort' => ['pageviews' => -1]],
            ['$limit' => 100],
        ], ['maxTimeMS' => 30000]));

        // Merge bounce/exit/time into page rows
        foreach ($pages as &$p) {
            $url = $p['url'] ?? '';
            if ($url instanceof \MongoDB\Model\BSONArray) { $url = $url[0] ?? ''; }
            if (!is_string($url)) { $url = is_scalar($url) ? (string) $url : ''; }
            $p['url'] = $url;
            $total = $urlTotal[$url] ?? 0;
            $p['avg_time'] = $total > 0 ? round(($urlTime[$url] ?? 0) / $total) : 0;
            $p['bounce_rate'] = $p['pageviews'] > 0 ? round((($urlBounce[$url] ?? 0) / $p['pageviews']) * 100, 1) : 0;
            $p['exit_rate'] = $p['pageviews'] > 0 ? round((($urlExit[$url] ?? 0) / $p['pageviews']) * 100, 1) : 0;
        }
        unset($p);

        return $this->successResponse(['pages' => $pages]);
    }

    /**
     * Search Analytics – aggregate site search events, Matomo "Behaviour > Site Search" equivalent
     */
    public function searchAnalytics(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        [$from, $to] = $this->parseDateRange($request->input('range', '30d'));
        $dateMatch = ['$gte' => new \MongoDB\BSON\UTCDateTime($from->getTimestamp() * 1000), '$lte' => new \MongoDB\BSON\UTCDateTime($to->getTimestamp() * 1000)];

        $collection = \DB::connection('mongodb')->getCollection('tracking_events');

        $keywords = iterator_to_array($collection->aggregate([
            ['$match' => ['tenant_id' => $tenantId, 'event_type' => 'search', 'created_at' => $dateMatch]],
            ['$group' => [
                '_id' => '$metadata.query',
                'searches' => ['$sum' => 1],
                'unique_sessions' => ['$addToSet' => '$session_id'],
                'avg_results' => ['$avg' => ['$ifNull' => ['$metadata.results_count', 0]]],
            ]],
            ['$project' => [
                '_id' => 0,
                'keyword' => '$_id',
                'searches' => 1,
                'unique' => ['$size' => '$unique_sessions'],
                'avg_results' => ['$round' => ['$avg_results', 0]],
            ]],
            ['$sort' => ['searches' => -1]],
            ['$limit' => 50],
        ], ['maxTimeMS' => 30000]));

        $totalSearches = $collection->countDocuments([
            'tenant_id' => $tenantId, 'event_type' => 'search', 'created_at' => $dateMatch,
        ]);

        $uniqueKeywords = count($keywords);

        // No-results searches
        $noResults = $collection->countDocuments([
            'tenant_id' => $tenantId, 'event_type' => 'search', 'created_at' => $dateMatch,
            'metadata.results_count' => 0,
        ]);

        return $this->successResponse([
            'total_searches' => $totalSearches,
            'unique_keywords' => $uniqueKeywords,
            'no_result_searches' => $noResults,
            'no_result_rate' => $totalSearches > 0 ? round($noResults / $totalSearches * 100, 1) : 0,
            'keywords' => $keywords,
        ]);
    }

    /**
     * Events Breakdown – aggregate events by event_type (category/action/label), Matomo "Behaviour > Events" equivalent
     */
    public function eventsBreakdown(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        [$from, $to] = $this->parseDateRange($request->input('range', '30d'));
        $dateMatch = ['$gte' => new \MongoDB\BSON\UTCDateTime($from->getTimestamp() * 1000), '$lte' => new \MongoDB\BSON\UTCDateTime($to->getTimestamp() * 1000)];

        $collection = \DB::connection('mongodb')->getCollection('tracking_events');

        $breakdown = iterator_to_array($collection->aggregate([
            ['$match' => ['tenant_id' => $tenantId, 'created_at' => $dateMatch]],
            ['$group' => [
                '_id' => [
                    'category' => ['$ifNull' => ['$metadata.event_category', '$event_type']],
                    'action' => ['$ifNull' => ['$metadata.event_action', '$event_type']],
                ],
                'count' => ['$sum' => 1],
                'unique_sessions' => ['$addToSet' => '$session_id'],
                'label' => ['$first' => ['$ifNull' => ['$metadata.event_label', '']]],
            ]],
            ['$project' => [
                '_id' => 0,
                'category' => '$_id.category',
                'action' => '$_id.action',
                'label' => 1,
                'count' => 1,
                'unique' => ['$size' => '$unique_sessions'],
            ]],
            ['$sort' => ['count' => -1]],
            ['$limit' => 100],
        ], ['maxTimeMS' => 30000]));

        // Summary by category only
        $categories = iterator_to_array($collection->aggregate([
            ['$match' => ['tenant_id' => $tenantId, 'created_at' => $dateMatch]],
            ['$group' => [
                '_id' => ['$ifNull' => ['$metadata.event_category', '$event_type']],
                'count' => ['$sum' => 1],
                'unique_sessions' => ['$addToSet' => '$session_id'],
            ]],
            ['$project' => [
                '_id' => 0,
                'category' => '$_id',
                'count' => 1,
                'unique' => ['$size' => '$unique_sessions'],
            ]],
            ['$sort' => ['count' => -1]],
        ], ['maxTimeMS' => 30000]));

        return $this->successResponse([
            'breakdown' => $breakdown,
            'categories' => $categories,
            'total_events' => array_sum(array_column($categories, 'count')),
        ]);
    }

    /**
     * Visitor Frequency – session count distribution, Matomo "Visitors > Visits Frequency" equivalent
     */
    public function visitorFrequency(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        [$from, $to] = $this->parseDateRange($request->input('range', '30d'));
        $dateMatch = ['$gte' => new \MongoDB\BSON\UTCDateTime($from->getTimestamp() * 1000), '$lte' => new \MongoDB\BSON\UTCDateTime($to->getTimestamp() * 1000)];

        $collection = \DB::connection('mongodb')->getCollection('tracking_events');

        // Count sessions per visitor_id, then bucket
        $perVisitor = iterator_to_array($collection->aggregate([
            ['$match' => ['tenant_id' => $tenantId, 'created_at' => $dateMatch, 'session_id' => ['$exists' => true]]],
            ['$group' => [
                '_id' => ['$ifNull' => ['$visitor_id', '$session_id']],
                'sessions' => ['$addToSet' => '$session_id'],
            ]],
            ['$project' => [
                '_id' => 0,
                'visit_count' => ['$size' => '$sessions'],
            ]],
        ], ['maxTimeMS' => 30000]));

        $buckets = ['1 visit' => 0, '2 visits' => 0, '3-5 visits' => 0, '6-10 visits' => 0, '11+ visits' => 0];
        foreach ($perVisitor as $v) {
            $c = $v['visit_count'] ?? ($v->visit_count ?? 0);
            if ($c <= 1) $buckets['1 visit']++;
            elseif ($c == 2) $buckets['2 visits']++;
            elseif ($c <= 5) $buckets['3-5 visits']++;
            elseif ($c <= 10) $buckets['6-10 visits']++;
            else $buckets['11+ visits']++;
        }

        $total = array_sum($buckets);
        $frequency = [];
        foreach ($buckets as $name => $value) {
            $frequency[] = [
                'name' => $name,
                'count' => $value,
                'percentage' => $total > 0 ? round($value / $total * 100, 1) : 0,
            ];
        }

        return $this->successResponse(['frequency' => $frequency, 'total_visitors' => $total]);
    }

    /**
     * Day-of-Week traffic + hourly heatmap from real data, Matomo "Visitors > Times" equivalent
     */
    public function dayOfWeek(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        [$from, $to] = $this->parseDateRange($request->input('range', '30d'));
        $dateMatch = ['$gte' => new \MongoDB\BSON\UTCDateTime($from->getTimestamp() * 1000), '$lte' => new \MongoDB\BSON\UTCDateTime($to->getTimestamp() * 1000)];

        $collection = \DB::connection('mongodb')->getCollection('tracking_events');

        // Traffic by day-of-week (1=Sunday .. 7=Saturday in Mongo)
        $byDay = iterator_to_array($collection->aggregate([
            ['$match' => ['tenant_id' => $tenantId, 'created_at' => $dateMatch]],
            ['$group' => [
                '_id' => ['$dayOfWeek' => ['date' => '$created_at', 'timezone' => config('ecom360.default_timezone', 'Asia/Kolkata')]],
                'count' => ['$sum' => 1],
            ]],
            ['$sort' => ['_id' => 1]],
        ], ['maxTimeMS' => 30000]));

        $dayNames = [1 => 'Sun', 2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat'];
        $dayOfWeekData = [];
        foreach ($dayNames as $num => $name) {
            $found = 0;
            foreach ($byDay as $d) {
                if (($d['_id'] ?? ($d->_id ?? 0)) == $num) {
                    $found = $d['count'] ?? ($d->count ?? 0);
                    break;
                }
            }
            $dayOfWeekData[] = ['day' => $name, 'count' => $found];
        }

        // Heatmap: hour x day-of-week
        $heatmap = iterator_to_array($collection->aggregate([
            ['$match' => ['tenant_id' => $tenantId, 'created_at' => $dateMatch]],
            ['$group' => [
                '_id' => [
                    'dow' => ['$dayOfWeek' => ['date' => '$created_at', 'timezone' => config('ecom360.default_timezone', 'Asia/Kolkata')]],
                    'hour' => ['$hour' => ['date' => '$created_at', 'timezone' => config('ecom360.default_timezone', 'Asia/Kolkata')]],
                ],
                'count' => ['$sum' => 1],
            ]],
        ], ['maxTimeMS' => 30000]));

        $heatmapData = [];
        foreach ($heatmap as $h) {
            $id = $h['_id'] ?? $h->_id;
            $dow = is_object($id) ? $id->dow : ($id['dow'] ?? 0);
            $hour = is_object($id) ? $id->hour : ($id['hour'] ?? 0);
            $count = $h['count'] ?? ($h->count ?? 0);
            $heatmapData[] = ['day' => $dow, 'hour' => $hour, 'count' => $count];
        }

        return $this->successResponse([
            'day_of_week' => $dayOfWeekData,
            'heatmap' => $heatmapData,
        ]);
    }

    /**
     * Recent Events – live/raw event stream for real-time dashboard, replaces Math.random() fake data
     */
    public function recentEvents(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $limit = min((int) $request->input('limit', 50), 200);

        $collection = \DB::connection('mongodb')->getCollection('tracking_events');

        $events = iterator_to_array($collection->find(
            ['tenant_id' => $tenantId],
            [
                'sort' => ['created_at' => -1],
                'limit' => $limit,
                'projection' => [
                    '_id' => 0,
                    'event_type' => 1,
                    'session_id' => 1,
                    'visitor_id' => 1,
                    'metadata.url' => 1,
                    'metadata.title' => 1,
                    'metadata.product_name' => 1,
                    'metadata.query' => 1,
                    'metadata.order_total' => 1,
                    'metadata.geo' => 1,
                    'metadata.country' => 1,
                    'metadata.city' => 1,
                    'custom_data' => 1,
                    'ip_address' => 1,
                    'user_agent' => 1,
                    'created_at' => 1,
                ],
            ]
        ));

        // Convert MongoDB dates to ISO strings
        foreach ($events as &$e) {
            if (isset($e['created_at']) && $e['created_at'] instanceof \MongoDB\BSON\UTCDateTime) {
                $e['created_at'] = $e['created_at']->toDateTime()->format('c');
            }
        }

        return $this->successResponse(['events' => $events]);
    }

    private function tenantId(): string
    {
        $user = Auth::user();

        if ($user === null || !isset($user->tenant_id)) {
            abort(403, 'Tenant context required.');
        }

        return (string) $user->tenant_id;
    }

    /** @return array{0: \Carbon\CarbonImmutable, 1: \Carbon\CarbonImmutable} */
    private function parseDateRange(string $range): array
    {
        if (preg_match('/^(\d+)d$/', $range, $m)) {
            $days = (int) $m[1];
            return [
                \Carbon\CarbonImmutable::now()->subDays($days)->startOfDay(),
                \Carbon\CarbonImmutable::now()->endOfDay(),
            ];
        }

        if ($range === 'ytd') {
            return [
                \Carbon\CarbonImmutable::now()->startOfYear(),
                \Carbon\CarbonImmutable::now()->endOfDay(),
            ];
        }

        if (str_contains($range, '|')) {
            [$from, $to] = explode('|', $range, 2);
            return [
                \Carbon\CarbonImmutable::parse($from)->startOfDay(),
                \Carbon\CarbonImmutable::parse($to)->endOfDay(),
            ];
        }

        return [
            \Carbon\CarbonImmutable::now()->subDays(30)->startOfDay(),
            \Carbon\CarbonImmutable::now()->endOfDay(),
        ];
    }
}
