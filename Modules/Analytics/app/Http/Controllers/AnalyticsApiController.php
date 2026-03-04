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

        return $this->successResponse([
            'by_country' => $service->getVisitorsByCountry($tenantId, $range),
            'by_city' => $service->getVisitorsByCity($tenantId, $range, 20),
            'devices' => $service->getDeviceBreakdown($tenantId, $range),
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
        ])));

        $active15min = count(iterator_to_array($collection->aggregate([
            ['$match' => [
                'tenant_id' => $tenantId,
                'created_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime($now->subMinutes(15)->getTimestamp() * 1000)],
            ]],
            ['$group' => ['_id' => '$session_id']],
        ])));

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
        ]));

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
        ]));

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
        ]));

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
        ]));

        return $this->successResponse([
            'category_views' => $categoryViews,
            'category_purchases' => $categoryPurchases,
        ]);
    }

    private function tenantId(): int
    {
        $user = Auth::user();

        if ($user === null || !isset($user->tenant_id)) {
            abort(403, 'Tenant context required.');
        }

        return (int) $user->tenant_id;
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
