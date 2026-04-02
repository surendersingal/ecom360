<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Analytics\Services\AiAnalyticsService;
use Modules\Analytics\Services\CampaignAnalyticsService;
use Modules\Analytics\Services\CohortAnalysisService;
use Modules\Analytics\Services\EcommerceFunnelService;
use Modules\Analytics\Services\GeographicAnalyticsService;
use Modules\Analytics\Services\ProductAnalyticsService;
use Modules\Analytics\Services\RevenueAnalyticsService;
use Modules\Analytics\Services\SegmentEvaluationService;
use Modules\Analytics\Services\SessionAnalyticsService;
use Modules\Analytics\Services\TrackingService;

/**
 * Handles all tenant analytics pages.
 * The ResolveTenant middleware shares $tenant via view()->share().
 * Each method fetches data from analytics services and passes to blade views.
 */
final class PageController extends Controller
{
    private function tid(Request $request): string
    {
        return (string) $request->attributes->get('tenant')->id;
    }

    private function dateRange(Request $request): string
    {
        return $request->query('date_range', '30d');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  ANALYTICS — Matomo-style Dashboard Pages
    // ════════════════════════════════════════════════════════════════════════

    /** Analytics overview dashboard — the main Matomo-style "Dashboard" */
    public function analyticsOverview(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.overview');
    }

    /** Visitors → Overview */
    public function analyticsVisitors(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.visitors');
    }

    /** Visitors → Visitor Log (individual sessions) */
    public function analyticsVisitorLog(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.visitor-log');
    }

    /** Visitors → Devices */
    public function analyticsDevices(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.devices');
    }

    /** Visitors → Locations */
    public function analyticsLocations(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.locations');
    }

    /** Visitors → Times */
    public function analyticsTimes(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.times');
    }

    /** Behaviour → Pages */
    public function analyticsPages(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.pages');
    }

    /** Behaviour → Entry Pages */
    public function analyticsEntryPages(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.entry-pages');
    }

    /** Behaviour → Exit Pages */
    public function analyticsExitPages(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.exit-pages');
    }

    /** Behaviour → Events (custom events) */
    public function analyticsEvents(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.events');
    }

    /** Behaviour → Site Search */
    public function analyticsSiteSearch(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.site-search');
    }

    /** Acquisition → Channels */
    public function analyticsChannels(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.channels');
    }

    /** Acquisition → Campaigns (UTM breakdown) */
    public function analyticsCampaigns(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.campaigns');
    }

    /** Acquisition → Referrers */
    public function analyticsReferrers(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.referrers');
    }

    /** Ecommerce → Overview (revenue, orders, AOV) */
    public function analyticsEcommerce(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.ecommerce');
    }

    /** Ecommerce → Products */
    public function analyticsProducts(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.products');
    }

    /** Ecommerce → Categories */
    public function analyticsCategories(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.categories');
    }

    /** Ecommerce → Conversion Funnel */
    public function analyticsFunnel(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.funnel');
    }

    /** Ecommerce → Abandoned Carts */
    public function analyticsAbandonedCarts(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.abandoned-carts');
    }

    /** AI & Insights → AI Insights */
    public function analyticsAiInsights(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.ai-insights');
    }

    /** AI & Insights → Ask a Question (NLQ) */
    public function analyticsAsk(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.ask');
    }

    /** AI & Insights → CLV Predictions & Revenue Forecast */
    public function analyticsPredictions(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.predictions');
    }

    /** AI & Insights → Competitive Benchmarks */
    public function analyticsBenchmarks(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.benchmarks');
    }

    /** Real-time → Live Dashboard */
    public function analyticsRealtime(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        try {
            $data = app(AiAnalyticsService::class)->getRealTimeOverview($tid);
        } catch (\Throwable) {
            $data = ['active_sessions' => 0, 'events_per_minute' => 0, 'top_pages' => [], 'geo_breakdown' => []];
        }
        return view('tenant.pages.analytics.realtime', ['rt' => $data]);
    }

    /** Real-time → Alerts */
    public function analyticsAlerts(Request $request, string $tenant): View
    {
        return view('tenant.pages.analytics.alerts');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  LEGACY ANALYTICS PAGES (kept for backwards compatibility)
    // ════════════════════════════════════════════════════════════════════════

    // ── Real-Time Traffic ────────────────────
    public function realtime(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);

        try {
            $data = app(AiAnalyticsService::class)->getRealTimeOverview($tid);
        } catch (\Throwable) {
            $data = ['active_sessions' => 0, 'events_per_minute' => 0, 'top_pages' => [], 'geo_breakdown' => []];
        }

        return view('tenant.pages.realtime', ['rt' => $data]);
    }

    // ── Page Visits ──────────────────────────
    public function pageVisits(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $dr  = $this->dateRange($request);

        try {
            $landing = app(SessionAnalyticsService::class)->getTopLandingPages($tid, $dr, 20);
            $exit    = app(SessionAnalyticsService::class)->getTopExitPages($tid, $dr, 20);
            $trend   = app(SessionAnalyticsService::class)->getDailySessionTrend($tid, $dr);
        } catch (\Throwable) {
            $landing = $exit = [];
            $trend   = ['dates' => [], 'sessions' => []];
        }

        return view('tenant.pages.page-visits', compact('landing', 'exit', 'trend'));
    }

    // ── Sessions Explorer ────────────────────
    public function sessions(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $dr  = $this->dateRange($request);

        try {
            $metrics     = app(SessionAnalyticsService::class)->getSessionMetrics($tid, $dr);
            $newVsReturn = app(SessionAnalyticsService::class)->getNewVsReturning($tid, $dr);
            $trend       = app(SessionAnalyticsService::class)->getDailySessionTrend($tid, $dr);
        } catch (\Throwable) {
            $metrics     = ['total_sessions' => 0, 'bounce_rate' => 0, 'avg_pages_per_session' => 0, 'avg_session_duration_formatted' => '0s', 'duration_distribution' => []];
            $newVsReturn = ['new_sessions' => 0, 'returning_sessions' => 0, 'new_pct' => 0, 'returning_pct' => 0, 'total_sessions' => 0];
            $trend       = ['dates' => [], 'sessions' => []];
        }

        return view('tenant.pages.sessions', compact('metrics', 'newVsReturn', 'trend'));
    }

    // ── Funnel Analytics ─────────────────────
    public function funnels(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $dr  = $this->dateRange($request);

        try {
            $funnel = app(EcommerceFunnelService::class)->getFunnelMetrics($tid, $dr);
        } catch (\Throwable) {
            $funnel = ['stages' => [], 'overall_conversion_pct' => 0, 'date_from' => now()->subDays(30)->toDateString(), 'date_to' => now()->toDateString()];
        }

        return view('tenant.pages.funnels', compact('funnel'));
    }

    // ── Category Analytics ───────────────────
    public function categories(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $dr  = $this->dateRange($request);

        try {
            $products = app(ProductAnalyticsService::class)->getProductPerformance($tid, $dr, 50);
        } catch (\Throwable) {
            $products = [];
        }

        return view('tenant.pages.categories', compact('products'));
    }

    // ── Product Analytics ────────────────────
    public function products(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $dr  = $this->dateRange($request);

        try {
            $topProducts = app(ProductAnalyticsService::class)->getTopProducts($tid, $dr, 'purchase', 20);
            $performance = app(ProductAnalyticsService::class)->getProductPerformance($tid, $dr, 20);
            $abandoned   = app(ProductAnalyticsService::class)->getCartAbandonmentProducts($tid, $dr, 20);
        } catch (\Throwable) {
            $topProducts = $performance = $abandoned = [];
        }

        return view('tenant.pages.products', compact('topProducts', 'performance', 'abandoned'));
    }

    // ── Campaign Analytics ───────────────────
    public function campaigns(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $dr  = $this->dateRange($request);

        try {
            $campaignPerf   = app(CampaignAnalyticsService::class)->getCampaignPerformance($tid, $dr);
            $utmBreakdown   = app(CampaignAnalyticsService::class)->getUtmBreakdown($tid, $dr);
            $channelAttrib  = app(CampaignAnalyticsService::class)->getChannelAttribution($tid, $dr);
            $referrers      = app(CampaignAnalyticsService::class)->getReferrerSources($tid, $dr, 20);
        } catch (\Throwable) {
            $campaignPerf  = [];
            $utmBreakdown  = ['sources' => [], 'mediums' => [], 'campaigns' => []];
            $channelAttrib = ['total_revenue' => 0, 'channels' => []];
            $referrers     = [];
        }

        return view('tenant.pages.campaigns', compact('campaignPerf', 'utmBreakdown', 'channelAttrib', 'referrers'));
    }

    // ── Customer Journey ─────────────────────
    public function customerJourney(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $dr  = $this->dateRange($request);

        try {
            $cohorts = app(CohortAnalysisService::class)->getRepeatPurchaseRate($tid, $dr);
        } catch (\Throwable) {
            $cohorts = ['total_customers' => 0, 'repeat_customers' => 0, 'one_time_customers' => 0, 'repeat_purchase_rate' => 0, 'frequency_distribution' => []];
        }

        return view('tenant.pages.customer-journey', compact('cohorts'));
    }

    // ── Cohort Analysis ──────────────────────
    public function cohorts(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);

        try {
            $retention = app(CohortAnalysisService::class)->getRetentionCohorts($tid, 6);
            $repeat    = app(CohortAnalysisService::class)->getRepeatPurchaseRate($tid, '90d');
            $clv       = app(CohortAnalysisService::class)->getClvBySegment($tid);
        } catch (\Throwable) {
            $retention = ['months' => [], 'retention_matrix' => []];
            $repeat    = ['total_customers' => 0, 'repeat_customers' => 0, 'one_time_customers' => 0, 'repeat_purchase_rate' => 0, 'frequency_distribution' => []];
            $clv       = [];
        }

        return view('tenant.pages.cohorts', compact('retention', 'repeat', 'clv'));
    }

    // ── Geographic Analytics ─────────────────
    public function geographic(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $dr  = $this->dateRange($request);

        try {
            $countries   = app(GeographicAnalyticsService::class)->getVisitorsByCountry($tid, $dr, 20);
            $cities      = app(GeographicAnalyticsService::class)->getVisitorsByCity($tid, $dr, 20);
            $devices     = app(GeographicAnalyticsService::class)->getDeviceBreakdown($tid, $dr);
            $hourly      = app(GeographicAnalyticsService::class)->getTrafficByHour($tid, $dr);
            $countryData = app(AiAnalyticsService::class)->getCountryAnalytics($tid, 30);
        } catch (\Throwable) {
            $countries   = $cities = [];
            $devices     = ['devices' => ['desktop' => 0, 'mobile' => 0, 'tablet' => 0, 'other' => 0], 'browsers' => [], 'total_sessions' => 0];
            $hourly      = ['hours' => [], 'views' => []];
            $countryData = ['countries' => [], 'total_countries' => 0];
        }

        return view('tenant.pages.geographic', compact('countries', 'cities', 'devices', 'hourly', 'countryData'));
    }

    // ── Audience Segments ────────────────────
    public function segments(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);

        try {
            $visitors  = app(SegmentEvaluationService::class)->getVisitorSegments($tid);
            $customers = app(SegmentEvaluationService::class)->getCustomerSegments($tid);
            $traffic   = app(SegmentEvaluationService::class)->getTrafficSegments($tid);
        } catch (\Throwable) {
            $visitors = $customers = $traffic = collect();
        }

        return view('tenant.pages.segments', compact('visitors', 'customers', 'traffic'));
    }

    // ── AI Insights ──────────────────────────
    public function aiInsights(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);

        try {
            $anomalies = app(AiAnalyticsService::class)->detectAnomalies($tid);
            $forecast  = app(AiAnalyticsService::class)->forecastRevenue($tid, 30, 7);
            $insights  = app(AiAnalyticsService::class)->generateInsights($tid);
        } catch (\Throwable) {
            $anomalies = collect();
            $forecast  = ['historical' => [], 'forecast' => [], 'trend' => 'stable', 'confidence' => 0];
            $insights  = collect();
        }

        return view('tenant.pages.ai-insights', compact('anomalies', 'forecast', 'insights'));
    }

    // ── Custom Events ────────────────────────
    public function customEvents(Request $request, string $tenant): View
    {
        return view('tenant.pages.custom-events');
    }

    // ── Webhooks ─────────────────────────────
    public function webhooks(Request $request, string $tenant): View
    {
        return view('tenant.pages.webhooks');
    }

    // ── Settings ─────────────────────────────
    public function settings(Request $request, string $tenant): View
    {
        return view('tenant.pages.settings');
    }

    // ── Integration ──────────────────────────
    public function integration(Request $request, string $tenant): View
    {
        return view('tenant.pages.integration');
    }

    // ── Revenue Waterfall ────────────────────
    public function revenueWaterfall(Request $request, string $tenant): View
    {
        return view('tenant.pages.revenue-waterfall');
    }

    // ── Customer Lifetime Value ──────────────
    public function clv(Request $request, string $tenant): View
    {
        return view('tenant.pages.clv');
    }

    // ── Why Analysis ─────────────────────────
    public function whyAnalysis(Request $request, string $tenant): View
    {
        return view('tenant.pages.why-analysis');
    }

    // ── Natural Language Query ────────────────
    public function nlq(Request $request, string $tenant): View
    {
        return view('tenant.pages.nlq');
    }

    // ── Smart Recommendations ────────────────
    public function recommendations(Request $request, string $tenant): View
    {
        return view('tenant.pages.recommendations');
    }

    // ── Competitive Benchmarks ───────────────
    public function benchmarks(Request $request, string $tenant): View
    {
        return view('tenant.pages.benchmarks');
    }

    // ── Marketing: Contacts ──────────────────
    public function marketingContacts(Request $request, string $tenant): View
    {
        return view('tenant.pages.marketing.contacts');
    }

    // ── Marketing: Campaigns ─────────────────
    public function marketingCampaigns(Request $request, string $tenant): View
    {
        return view('tenant.pages.marketing.campaigns');
    }

    // ── Marketing: Templates ─────────────────
    public function marketingTemplates(Request $request, string $tenant): View
    {
        return view('tenant.pages.marketing.templates');
    }

    // ── Marketing: Automation Flows ──────────
    public function marketingFlows(Request $request, string $tenant): View
    {
        return view('tenant.pages.marketing.flows');
    }

    // ── Marketing: Flow Builder ──────────────
    public function flowBuilder(Request $request, string $tenant, int $flowId): View
    {
        return view('tenant.pages.marketing.flow-builder', ['flowId' => $flowId]);
    }

    // ── Marketing: Channels ──────────────────
    public function marketingChannels(Request $request, string $tenant): View
    {
        return view('tenant.pages.marketing.channels');
    }

    // ── Marketing: Audience Sync ─────────────
    public function marketingAudienceSync(Request $request, string $tenant): View
    {
        return view('tenant.pages.marketing.audience-sync');
    }

    // ── BI: Dashboards ───────────────────────
    public function biDashboards(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.dashboards');
    }

    // ── BI: Reports ──────────────────────────
    public function biReports(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.reports');
    }

    // ── BI: KPI Tracker ─────────────────────
    public function biKpis(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.kpis');
    }

    // ── BI: Alerts ───────────────────────────
    public function biAlerts(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.alerts');
    }

    // ── BI: Predictions ─────────────────────
    public function biPredictions(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.predictions');
    }

    // ── BI: Data Exports ─────────────────────
    public function biExports(Request $request, string $tenant): View
    {
        return view('tenant.pages.bi.exports');
    }

    // ── Behavioral Triggers ──────────────────
    public function behavioralTriggers(Request $request, string $tenant): View
    {
        return view('tenant.pages.behavioral-triggers');
    }

    // ── Real-Time Alerts ─────────────────────
    public function realtimeAlerts(Request $request, string $tenant): View
    {
        return view('tenant.pages.realtime-alerts');
    }

    // ══════════════════════════════════════════
    // AI SEARCH & DISCOVERY (UC1-10)
    // ══════════════════════════════════════════

    // UC1 – AI Gift Concierge
    public function giftConcierge(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        try {
            $data = app(\Modules\AiSearch\Services\SemanticSearchService::class)->giftConcierge($tid, []);
        } catch (\Throwable) {
            $data = ['suggestions' => [], 'occasion_tags' => [], 'budget_ranges' => []];
        }
        return view('tenant.pages.search.gift-concierge', ['results' => $data]);
    }

    // UC2 – Shop the Room Visual Search
    public function shopTheRoom(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        try {
            $data = app(\Modules\AiSearch\Services\VisualSearchService::class)->shopTheRoom($tid, '', []);
        } catch (\Throwable) {
            $data = ['scene_type' => '', 'detected_objects' => [], 'products' => []];
        }
        return view('tenant.pages.search.shop-the-room', ['results' => $data]);
    }

    // UC3 – Personalized Size Filtering
    public function personalizedSize(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        try {
            $data = app(\Modules\AiSearch\Services\PersonalizedSearchService::class)->personalizedSizeSearch($tid, '', '');
        } catch (\Throwable) {
            $data = ['query' => '', 'products' => [], 'size_profile' => []];
        }
        return view('tenant.pages.search.personalized-size', ['results' => $data]);
    }

    // UC4 – Out-of-Stock Smart Rerouting
    public function oosReroute(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        try {
            $data = app(\Modules\AiSearch\Services\PersonalizedSearchService::class)->outOfStockReroute($tid, '');
        } catch (\Throwable) {
            $data = ['original_product' => [], 'alternatives' => [], 'restock_estimate' => null];
        }
        return view('tenant.pages.search.oos-reroute', ['results' => $data]);
    }

    // UC5 – Typo & Phonetic Auto-Correction
    public function typoCorrection(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        try {
            $data = app(\Modules\AiSearch\Services\SemanticSearchService::class)->autoCorrect($tid, '');
        } catch (\Throwable) {
            $data = ['original' => '', 'corrected' => '', 'suggestions' => [], 'products' => []];
        }
        return view('tenant.pages.search.typo-correction', ['results' => $data]);
    }

    // UC6 – Subscription Discovery Engine
    public function subscriptionDiscovery(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        try {
            $data = app(\Modules\AiSearch\Services\SemanticSearchService::class)->subscriptionDiscovery($tid);
        } catch (\Throwable) {
            $data = ['products' => [], 'plans' => [], 'savings' => 0];
        }
        return view('tenant.pages.search.subscription-discovery', ['results' => $data]);
    }

    // UC7 – B2B Search Gates
    public function b2bSearch(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        try {
            $data = app(\Modules\AiSearch\Services\PersonalizedSearchService::class)->b2bSearch($tid, '', []);
        } catch (\Throwable) {
            $data = ['products' => [], 'tier_pricing' => [], 'moq_rules' => []];
        }
        return view('tenant.pages.search.b2b-search', ['results' => $data]);
    }

    // UC8 – Trend-Injected Ranking
    public function trendRanking(Request $request, string $tenant): View
    {
        $tid = (int) $this->tid($request);
        try {
            $raw = app(\Modules\AiSearch\Services\PersonalizedSearchService::class)->trendInjectedSearch($tid, []);
            $data = [
                'products'         => $raw['results'] ?? [],
                'trending_signals' => $raw['trend_injection'] ?? [],
                'social_mentions'  => [],
            ];
        } catch (\Throwable) {
            $data = ['products' => [], 'trending_signals' => [], 'social_mentions' => []];
        }
        return view('tenant.pages.search.trend-ranking', ['results' => $data]);
    }

    // UC9 – Feature Comparison Matrix
    public function comparisonSearch(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        try {
            $data = app(\Modules\AiSearch\Services\SemanticSearchService::class)->featureComparison($tid, []);
        } catch (\Throwable) {
            $data = ['products' => [], 'comparison_matrix' => [], 'recommendation' => ''];
        }
        return view('tenant.pages.search.comparison', ['results' => $data]);
    }

    // UC10 – Voice-to-Cart
    public function voiceToCart(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        try {
            $data = app(\Modules\AiSearch\Services\SemanticSearchService::class)->voiceToCart($tid, '');
        } catch (\Throwable) {
            $data = ['transcript' => [], 'parsed_items' => [], 'cart' => []];
        }
        return view('tenant.pages.search.voice-to-cart', ['results' => $data]);
    }

    // ══════════════════════════════════════════
    // HYPER-PERSONALIZED MARKETING (UC11-20)
    // ══════════════════════════════════════════

    // UC11 – Weather-Triggered Campaigns
    public function weatherCampaigns(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Marketing\Services\HyperPersonalizationService::class)->weatherTriggeredCampaigns($tid, $range);
        } catch (\Throwable) {
            $data = ['campaigns' => [], 'weather_zones' => [], 'revenue_impact' => 0];
        }
        return view('tenant.pages.marketing.weather-campaigns', ['data' => $data]);
    }

    // UC12 – Payday Surge Pricing
    public function paydaySurge(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Marketing\Services\HyperPersonalizationService::class)->paydaySurgeCampaigns($tid, $range);
        } catch (\Throwable) {
            $data = ['surge_windows' => [], 'campaigns' => [], 'revenue_uplift' => 0];
        }
        return view('tenant.pages.marketing.payday-surge', ['data' => $data]);
    }

    // UC13 – Cart Abandonment Down-Selling
    public function cartDownsell(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Marketing\Services\HyperPersonalizationService::class)->cartAbandonmentDownSell($tid, $range);
        } catch (\Throwable) {
            $data = ['abandoned_carts' => [], 'downsell_offers' => 0, 'recovery_rate' => 0];
        }
        return view('tenant.pages.marketing.cart-downsell', ['data' => $data]);
    }

    // UC14 – Post-Purchase UGC Incentive
    public function ugcIncentive(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Marketing\Services\HyperPersonalizationService::class)->postPurchaseUgcIncentive($tid, $range);
        } catch (\Throwable) {
            $data = ['campaigns' => [], 'ugc_submissions' => 0, 'conversion_boost' => 0];
        }
        return view('tenant.pages.marketing.ugc-incentive', ['data' => $data]);
    }

    // UC15 – Back-in-Stock Micro-Targeting
    public function backInStock(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Marketing\Services\HyperPersonalizationService::class)->backInStockMicroTarget($tid, $range);
        } catch (\Throwable) {
            $data = ['products' => [], 'waitlist_subscribers' => 0, 'notification_sent' => 0];
        }
        return view('tenant.pages.marketing.back-in-stock', ['data' => $data]);
    }

    // UC16 – Discount Addiction Flagging
    public function discountAddiction(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Marketing\Services\AdvancedMarketingService::class)->discountAddictionAnalysis($tid, $range);
        } catch (\Throwable) {
            $data = ['flagged_customers' => [], 'total_flagged' => 0, 'revenue_at_risk' => 0];
        }
        return view('tenant.pages.marketing.discount-addiction', ['data' => $data]);
    }

    // UC17 – VIP Early Access
    public function vipEarlyAccess(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Marketing\Services\AdvancedMarketingService::class)->vipEarlyAccess($tid, $range);
        } catch (\Throwable) {
            $data = ['vip_customers' => 0, 'upcoming_launches' => [], 'engagement_rate' => 0];
        }
        return view('tenant.pages.marketing.vip-early-access', ['data' => $data]);
    }

    // UC18 – Churn-Risk Winback
    public function churnWinback(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Marketing\Services\AdvancedMarketingService::class)->churnRiskWinback($tid, $range);
        } catch (\Throwable) {
            $data = ['at_risk_customers' => [], 'winback_campaigns' => 0, 'recovery_rate' => 0];
        }
        return view('tenant.pages.marketing.churn-winback', ['data' => $data]);
    }

    // UC19 – Smart Replenishment Reminders
    public function replenishment(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Marketing\Services\AdvancedMarketingService::class)->smartReplenishment($tid, $range);
        } catch (\Throwable) {
            $data = ['products' => [], 'reminders_sent' => 0, 'reorder_rate' => 0];
        }
        return view('tenant.pages.marketing.replenishment', ['data' => $data]);
    }

    // UC20 – Milestone Automation
    public function milestones(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Marketing\Services\AdvancedMarketingService::class)->milestoneAutomation($tid, $range);
        } catch (\Throwable) {
            $data = ['milestones' => [], 'triggered' => 0, 'engagement_rate' => 0];
        }
        return view('tenant.pages.marketing.milestones', ['data' => $data]);
    }

    // ══════════════════════════════════════════
    // AUTONOMOUS BUSINESS OPS (UC21-30)
    // ══════════════════════════════════════════

    // UC21 – Stale Inventory Auto-Pricing
    public function stalePricing(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\BusinessIntelligence\Services\AutonomousOpsService::class)->staleInventoryPricing($tid, $range);
        } catch (\Throwable) {
            $data = ['stale_products' => [], 'price_adjustments' => [], 'projected_savings' => 0];
        }
        return view('tenant.pages.bi.stale-pricing', ['data' => $data]);
    }

    // UC22 – Real-Time Fraud Scoring
    public function fraudScoring(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\BusinessIntelligence\Services\AutonomousOpsService::class)->fraudScoring($tid, $range);
        } catch (\Throwable) {
            $data = ['flagged_orders' => [], 'risk_distribution' => [], 'blocked_amount' => 0];
        }
        return view('tenant.pages.bi.fraud-scoring', ['data' => $data]);
    }

    // UC23 – Demand Forecasting
    public function demandForecast(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\BusinessIntelligence\Services\AutonomousOpsService::class)->demandForecasting($tid, $range);
        } catch (\Throwable) {
            $data = ['forecasts' => [], 'confidence' => 0, 'recommendations' => []];
        }
        return view('tenant.pages.bi.demand-forecast', ['data' => $data]);
    }

    // UC24 – Shipping Cost Analyzer
    public function shippingAnalyzer(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\BusinessIntelligence\Services\AutonomousOpsService::class)->shippingCostAnalyzer($tid, $range);
        } catch (\Throwable) {
            $data = ['carriers' => [], 'cost_breakdown' => [], 'optimization_tips' => []];
        }
        return view('tenant.pages.bi.shipping-analyzer', ['data' => $data]);
    }

    // UC25 – Return Rate Anomaly Detection
    public function returnAnomaly(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\BusinessIntelligence\Services\AutonomousOpsService::class)->returnRateAnomaly($tid, $range);
        } catch (\Throwable) {
            $data = ['anomalies' => [], 'return_rate_trend' => [], 'top_returned_products' => []];
        }
        return view('tenant.pages.bi.return-anomaly', ['data' => $data]);
    }

    // UC26 – Product Cannibalization Detection
    public function cannibalization(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\BusinessIntelligence\Services\AdvancedBIService::class)->productCannibalization($tid, $range);
        } catch (\Throwable) {
            $data = ['pairs' => [], 'revenue_impact' => 0, 'recommendations' => []];
        }
        return view('tenant.pages.bi.cannibalization', ['data' => $data]);
    }

    // UC27 – LTV vs CAC Health Monitor
    public function ltvVsCac(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\BusinessIntelligence\Services\AdvancedBIService::class)->ltvVsCacHealth($tid, $range);
        } catch (\Throwable) {
            $data = ['ltv' => 0, 'cac' => 0, 'ratio' => 0, 'trend' => [], 'channels' => []];
        }
        return view('tenant.pages.bi.ltv-vs-cac', ['data' => $data]);
    }

    // UC28 – Conversion Probability Scoring
    public function conversionProbability(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\BusinessIntelligence\Services\AdvancedBIService::class)->conversionProbability($tid, $range);
        } catch (\Throwable) {
            $data = ['sessions' => [], 'score_distribution' => [], 'avg_probability' => 0];
        }
        return view('tenant.pages.bi.conversion-probability', ['data' => $data]);
    }

    // UC29 – Device × Revenue Mapping
    public function deviceRevenue(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\BusinessIntelligence\Services\AdvancedBIService::class)->deviceRevenueMapping($tid, $range);
        } catch (\Throwable) {
            $data = ['devices' => [], 'revenue_by_device' => [], 'conversion_by_device' => []];
        }
        return view('tenant.pages.bi.device-revenue', ['data' => $data]);
    }

    // UC30 – Cohort by Acquisition Source
    public function cohortAcquisition(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\BusinessIntelligence\Services\AdvancedBIService::class)->cohortByAcquisition($tid, $range);
        } catch (\Throwable) {
            $data = ['cohorts' => [], 'retention_matrix' => [], 'best_source' => ''];
        }
        return view('tenant.pages.bi.cohort-acquisition', ['data' => $data]);
    }

    // ══════════════════════════════════════════
    // PROACTIVE CUSTOMER SUPPORT (UC31-40)
    // ══════════════════════════════════════════

    // UC31 – Order Modification Bot
    public function orderModification(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Chatbot\Services\ProactiveSupportService::class)->orderModification($tid, $range);
        } catch (\Throwable) {
            $data = ['modifications' => [], 'success_rate' => 0, 'avg_response_time' => 0];
        }
        return view('tenant.pages.support.order-modification', ['data' => $data]);
    }

    // UC32 – Sentiment-Based Escalation Router
    public function sentimentRouter(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Chatbot\Services\ProactiveSupportService::class)->sentimentEscalation($tid, $range);
        } catch (\Throwable) {
            $data = ['tickets' => [], 'sentiment_distribution' => 'N/A', 'escalation_rate' => 0];
        }
        return view('tenant.pages.support.sentiment-router', ['data' => $data]);
    }

    // UC33 – VIP Greeting Protocol
    public function vipGreeting(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Chatbot\Services\ProactiveSupportService::class)->vipGreeting($tid, $range);
        } catch (\Throwable) {
            $data = ['vip_sessions' => [], 'greetings_sent' => 0, 'satisfaction_score' => 0];
        }
        return view('tenant.pages.support.vip-greeting', ['data' => $data]);
    }

    // UC34 – Warranty & Returns Claim Processor
    public function warrantyClaims(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Chatbot\Services\ProactiveSupportService::class)->warrantyClaim($tid, $range);
        } catch (\Throwable) {
            $data = ['claims' => [], 'processed' => 0, 'avg_resolution_time' => 0];
        }
        return view('tenant.pages.support.warranty-claims', ['data' => $data]);
    }

    // UC35 – Multi-Item Sizing Assistant
    public function sizingAssistant(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Chatbot\Services\ProactiveSupportService::class)->multiItemSizingAssistant($tid, $range);
        } catch (\Throwable) {
            $data = ['sessions' => [], 'accuracy_rate' => 0, 'return_reduction' => 0];
        }
        return view('tenant.pages.support.sizing-assistant', ['data' => $data]);
    }

    // UC36 – Visual Order Tracking
    public function visualOrderTracking(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Chatbot\Services\AdvancedChatService::class)->visualOrderTracking($tid, $range);
        } catch (\Throwable) {
            $data = ['tracked_orders' => [], 'avg_delivery_time' => 0, 'active_shipments' => 0];
        }
        return view('tenant.pages.support.order-tracking', ['data' => $data]);
    }

    // UC37 – Pre-Checkout Objection Handler
    public function objectionHandler(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Chatbot\Services\AdvancedChatService::class)->preCheckoutObjectionHandler($tid, $range);
        } catch (\Throwable) {
            $data = ['objections' => [], 'resolved' => 0, 'conversion_lift' => 0];
        }
        return view('tenant.pages.support.objection-handler', ['data' => $data]);
    }

    // UC38 – Subscription Management Bot
    public function subscriptionMgmt(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Chatbot\Services\AdvancedChatService::class)->subscriptionManagement($tid, $range);
        } catch (\Throwable) {
            $data = ['active_subscriptions' => [], 'changes' => 0, 'churn_prevented' => 0];
        }
        return view('tenant.pages.support.subscription-mgmt', ['data' => $data]);
    }

    // UC39 – Gift Card Builder
    public function giftCardBuilder(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Chatbot\Services\AdvancedChatService::class)->giftCardBuilder($tid, $range);
        } catch (\Throwable) {
            $data = ['cards_created' => 0, 'revenue' => 0, 'popular_designs' => 'N/A'];
        }
        return view('tenant.pages.support.gift-cards', ['data' => $data]);
    }

    // UC40 – Video Review Guide
    public function videoReviews(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Chatbot\Services\AdvancedChatService::class)->videoReviewGuide($tid, $range);
        } catch (\Throwable) {
            $data = ['reviews' => [], 'avg_rating' => 0, 'video_submissions' => 0];
        }
        return view('tenant.pages.support.video-reviews', ['data' => $data]);
    }

    // ══════════════════════════════════════════
    // NEXT-GEN ANALYTICS & CDP (UC41-50)
    // ══════════════════════════════════════════

    // UC41 – Offline-Online Stitching
    public function offlineStitching(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Analytics\Services\CdpAdvancedService::class)->offlineOnlineStitching($tid, $range);
        } catch (\Throwable) {
            $data = ['stitched_profiles' => 0, 'match_rate' => 0, 'channels' => []];
        }
        return view('tenant.pages.cdp.offline-stitching', ['data' => $data]);
    }

    // UC42 – Zombie Account Reactivation
    public function zombieAccounts(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Analytics\Services\CdpAdvancedService::class)->zombieAccountReactivation($tid, $range);
        } catch (\Throwable) {
            $data = ['zombie_accounts' => [], 'reactivation_campaigns' => [], 'recovery_rate' => 0];
        }
        return view('tenant.pages.cdp.zombie-accounts', ['data' => $data]);
    }

    // UC43 – Product Affinity Mapping
    public function productAffinity(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Analytics\Services\CdpAdvancedService::class)->productAffinityMapping($tid, $range);
        } catch (\Throwable) {
            $data = ['affinity_pairs' => [], 'clusters' => [], 'cross_sell_opportunities' => 0];
        }
        return view('tenant.pages.cdp.product-affinity', ['data' => $data]);
    }

    // UC44 – Zero-Party Data Engine
    public function zeroPartyData(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Analytics\Services\CdpAdvancedService::class)->zeroPartyDataEngine($tid, $range);
        } catch (\Throwable) {
            $data = ['surveys' => [], 'preferences_collected' => 0, 'enrichment_rate' => 0];
        }
        return view('tenant.pages.cdp.zero-party-data', ['data' => $data]);
    }

    // UC45 – Refund Impact Analyzer
    public function refundImpact(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Analytics\Services\CdpAdvancedService::class)->refundImpactAnalyzer($tid, $range);
        } catch (\Throwable) {
            $data = ['refund_total' => 0, 'impact_by_category' => [], 'trend' => []];
        }
        return view('tenant.pages.cdp.refund-impact', ['data' => $data]);
    }

    // UC46 – Multi-Touch Attribution
    public function multiTouchAttribution(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Analytics\Services\AdvancedAnalyticsOpsService::class)->multiTouchAttribution($tid, $range);
        } catch (\Throwable) {
            $data = ['channels' => [], 'attribution_model' => '', 'roas_by_channel' => []];
        }
        return view('tenant.pages.cdp.attribution', ['data' => $data]);
    }

    // UC47 – Session Journey Replay
    public function journeyReplay(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Analytics\Services\AdvancedAnalyticsOpsService::class)->sessionJourneyReplay($tid, $range);
        } catch (\Throwable) {
            $data = ['sessions' => [], 'avg_duration' => 0, 'drop_off_points' => []];
        }
        return view('tenant.pages.cdp.journey-replay', ['data' => $data]);
    }

    // UC48 – GDPR Purge Simulator
    public function gdprPurge(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        try {
            $data = app(\Modules\Analytics\Services\AdvancedAnalyticsOpsService::class)->gdprPurgeSimulator($tid);
        } catch (\Throwable) {
            $data = ['affected_records' => 0, 'tables' => [], 'estimated_impact' => ''];
        }
        return view('tenant.pages.cdp.gdpr-purge', ['data' => $data]);
    }

    // UC49 – Form-Field Abandonment
    public function formAbandonment(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $data = app(\Modules\Analytics\Services\AdvancedAnalyticsOpsService::class)->formFieldAbandonment($tid, $range);
        } catch (\Throwable) {
            $data = ['forms' => [], 'field_drop_offs' => [], 'completion_rate' => 0];
        }
        return view('tenant.pages.cdp.form-abandonment', ['data' => $data]);
    }

    // UC50 – Cross-Tenant Benchmarking
    public function crossBenchmarking(Request $request, string $tenant): View
    {
        $tid = $this->tid($request);
        $range = $this->dateRange($request);
        try {
            $raw = app(\Modules\Analytics\Services\AdvancedAnalyticsOpsService::class)->crossTenantBenchmarking($tid, $range);
            // Normalize: service returns different keys than blade expects
            $industryAvg = $raw['industry_avg'] ?? [];
            $data = [
                'percentile'  => $raw['current_percentile'] ?? 0,
                'industry_avg' => is_array($industryAvg) ? ($industryAvg['avg_revenue'] ?? 'N/A') : $industryAvg,
                'benchmarks'  => collect($raw['benchmarks'] ?? [])->map(fn($b) => [
                    'name'         => $b['tenant_id'] ?? '-',
                    'your_value'   => $b['revenue_30d'] ?? 0,
                    'industry_avg' => $b['aov'] ?? 0,
                    'percentile'   => $b['conversion_rate'] ?? 0,
                    'trend'        => ($b['revenue_30d'] ?? 0) > 0 ? 'up' : 'flat',
                ])->toArray(),
            ];
        } catch (\Throwable) {
            $data = ['benchmarks' => [], 'percentile' => 0, 'industry_avg' => 'N/A'];
        }
        return view('tenant.pages.cdp.cross-benchmarking', ['data' => $data]);
    }

    // ── Data Sync ────────────────────────────
    public function datasyncConnections(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');
        $connections = \Modules\DataSync\Models\SyncConnection::where('tenant_id', $tenantModel->id)
            ->orderByDesc('last_heartbeat_at')->get();
        return view('tenant.pages.datasync.connections', ['connections' => $connections]);
    }

    public function datasyncPermissions(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');
        $connections = \Modules\DataSync\Models\SyncConnection::where('tenant_id', $tenantModel->id)->with('permissions')->get();
        return view('tenant.pages.datasync.permissions', ['connections' => $connections]);
    }

    public function datasyncProducts(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');
        $products = \Modules\DataSync\Models\SyncedProduct::where('tenant_id', (string) $tenantModel->id)
            ->orderByDesc('synced_at')->paginate(25);
        return view('tenant.pages.datasync.products', ['products' => $products]);
    }

    public function datasyncCategories(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');
        $categories = \Modules\DataSync\Models\SyncedCategory::where('tenant_id', (string) $tenantModel->id)
            ->orderBy('level')->orderBy('name')->paginate(25);
        return view('tenant.pages.datasync.categories', ['categories' => $categories]);
    }

    public function datasyncOrders(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');
        $orders = \Modules\DataSync\Models\SyncedOrder::where('tenant_id', (string) $tenantModel->id)
            ->orderByDesc('synced_at')->paginate(25);
        return view('tenant.pages.datasync.orders', ['orders' => $orders]);
    }

    public function datasyncCustomers(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');
        $customers = \Modules\DataSync\Models\SyncedCustomer::where('tenant_id', (string) $tenantModel->id)
            ->orderByDesc('synced_at')->paginate(25);
        return view('tenant.pages.datasync.customers', ['customers' => $customers]);
    }

    public function datasyncInventory(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');
        $inventory = \Modules\DataSync\Models\SyncedInventory::where('tenant_id', (string) $tenantModel->id)
            ->orderBy('sku')->paginate(25);
        return view('tenant.pages.datasync.inventory', ['inventory' => $inventory]);
    }

    public function datasyncLogs(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');
        $logs = \Modules\DataSync\Models\SyncLog::where('tenant_id', $tenantModel->id)
            ->orderByDesc('created_at')->paginate(25);
        return view('tenant.pages.datasync.logs', ['logs' => $logs]);
    }

    // ── AI Search Settings ───────────────────
    public function searchSettings(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');
        $settings = \App\Models\TenantSetting::where('tenant_id', $tenantModel->id)
            ->where('module', 'aisearch')
            ->pluck('value', 'key')
            ->toArray();

        return view('tenant.pages.search.settings', ['settings' => $settings]);
    }

    public function searchSettingsSave(Request $request, string $tenant): \Illuminate\Http\JsonResponse
    {
        $tenantModel = $request->attributes->get('tenant');

        $keys = [
            // Engine config
            'search_results_per_page', 'search_max_raw_results', 'search_min_query_length',
            'search_debounce_ms', 'search_throttle_rate',
            // Relevance weights
            'weight_text_relevance', 'weight_margin_boost', 'weight_popularity',
            'weight_freshness', 'weight_stock',
            // Facets
            'facet_brands_enabled', 'facet_categories_enabled', 'facet_price_enabled',
            'facet_color_enabled', 'facet_size_enabled', 'facet_rating_enabled',
            'facet_brands_limit', 'facet_categories_limit',
            // Autocomplete & Suggestions
            'autocomplete_enabled', 'autocomplete_max_suggestions', 'suggest_cache_ttl',
            'trending_enabled', 'trending_window_days', 'trending_max_results',
            'typo_correction_enabled', 'smart_price_fallback',
            // Synonyms & Aliases
            'custom_synonyms', 'category_aliases',
            // Currency & Display
            'search_currency_code', 'search_currency_symbol', 'search_price_format',
            'search_store_base_url', 'search_product_url_pattern', 'search_no_image_url',
            // Advanced features
            'nlq_enabled', 'fuzzy_matching_enabled', 'phonetic_matching_enabled',
            'synonym_expansion_enabled', 'gift_concierge_enabled', 'visual_search_enabled',
            'voice_search_enabled', 'oos_reroute_enabled', 'comparison_enabled',
            'personalized_size_enabled',
            // Widget appearance
            'search_widget_color', 'search_placeholder_text', 'suggest_show_images',
            'suggest_show_prices', 'show_brand_in_results', 'search_keyboard_shortcut',
            // SRP config
            'srp_default_view', 'srp_default_sort', 'srp_per_page_options',
            'srp_show_discount_badge', 'srp_show_stock_status', 'srp_show_rating',
        ];

        foreach ($keys as $key) {
            $value = $request->input($key);
            if ($value !== null) {
                \App\Models\TenantSetting::updateOrCreate(
                    ['tenant_id' => $tenantModel->id, 'module' => 'aisearch', 'key' => $key],
                    ['value' => $value],
                );
            }
        }

        \Illuminate\Support\Facades\Cache::forget("tenant_settings:{$tenantModel->id}:aisearch");

        return response()->json(['success' => true, 'message' => 'Search settings saved.']);
    }

    public function searchAnalyticsDashboard(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');
        $tid = (string) $tenantModel->id;
        $days = (int) $request->query('days', 30);

        try {
            $searchService = app(\Modules\AiSearch\Services\SearchService::class);
            $analytics = $searchService->getAnalytics($tid, $days);
            $trending  = $searchService->getTrending($tid, 15);
        } catch (\Throwable) {
            $analytics = [
                'total_searches' => 0, 'click_through_rate' => 0, 'conversion_rate' => 0,
                'zero_result_rate' => 0, 'avg_response_time' => 0,
                'top_queries' => [], 'zero_result_queries' => [],
            ];
            $trending = ['trending' => []];
        }

        return view('tenant.pages.search.analytics', [
            'analytics' => $analytics,
            'trending'  => $trending['trending'] ?? [],
            'days'      => $days,
        ]);
    }

    // ── AI Chatbot Settings ──────────────────
    public function chatbotSettings(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');
        $settings = \App\Models\TenantSetting::where('tenant_id', $tenantModel->id)
            ->where('module', 'chatbot')
            ->pluck('value', 'key')
            ->toArray();

        return view('tenant.pages.chatbot.settings', ['settings' => $settings]);
    }

    public function chatbotFlows(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');
        $settings = \App\Models\TenantSetting::where('tenant_id', $tenantModel->id)
            ->where('module', 'chatbot')
            ->pluck('value', 'key')
            ->toArray();

        $flows = json_decode($settings['custom_flows'] ?? '[]', true) ?: [];

        return view('tenant.pages.chatbot.flows', ['settings' => $settings, 'flows' => $flows]);
    }

    public function chatbotSettingsSave(Request $request, string $tenant): \Illuminate\Http\JsonResponse
    {
        $tenantModel = $request->attributes->get('tenant');

        $keys = [
            // Widget appearance
            'chatbot_name', 'chatbot_greeting', 'chatbot_avatar', 'chatbot_color',
            'chatbot_position', 'chatbot_width', 'chatbot_height', 'chatbot_offline_message',
            // Behavior
            'chatbot_enabled', 'chatbot_auto_open_seconds', 'chatbot_language',
            'chatbot_product_cards', 'chatbot_max_products', 'chatbot_quick_replies',
            'chatbot_typing_indicator', 'chatbot_sound_enabled',
            // Intent toggles
            'intent_greeting', 'intent_farewell', 'intent_product_inquiry', 'intent_order_tracking',
            'intent_checkout_help', 'intent_return_request', 'intent_coupon_inquiry',
            'intent_size_help', 'intent_shipping_inquiry', 'intent_add_to_cart',
            // Advanced features
            'chatbot_rage_click', 'chatbot_sentiment_escalation', 'chatbot_vip_greeting',
            'chatbot_objection_handler', 'chatbot_visual_tracking', 'chatbot_order_modification',
            'chatbot_warranty_claims', 'chatbot_multi_sizing',
            'chatbot_subscription_mgmt', 'chatbot_gift_card', 'chatbot_video_review',
            // Greeting & fallback buttons
            'chatbot_greeting_buttons', 'chatbot_fallback_buttons', 'tpl_general_fallback',
            // Response templates
            'tpl_shipping', 'tpl_returns', 'tpl_no_products', 'tpl_farewell',
            // Proactive triggers
            'trigger_cart_abandonment', 'trigger_cart_delay', 'trigger_exit_intent',
            'trigger_inactivity', 'trigger_inactivity_delay', 'trigger_scroll_depth',
            'trigger_scroll_percent', 'trigger_cart_message',
            // Escalation
            'escalation_enabled', 'escalation_email', 'sentiment_threshold',
            'vip_ltv_threshold', 'max_bot_turns',
            // Business hours
            'business_hours_enabled', 'business_timezone', 'business_hours_start',
            'business_hours_end', 'business_days',
            // Custom keywords
            'custom_product_keywords',
            // Store integration
            'chatbot_store_url', 'chatbot_product_url_pattern', 'chatbot_currency_symbol',
            'chatbot_free_shipping', 'chatbot_return_days', 'chatbot_warranty_days',
            // Emergency
            'chatbot_maintenance', 'chatbot_maintenance_message',
            'chatbot_max_messages', 'chatbot_rate_limit',
            // Custom flows (JSON)
            'custom_flows',
        ];

        foreach ($keys as $key) {
            $value = $request->input($key);
            if ($value !== null) {
                \App\Models\TenantSetting::updateOrCreate(
                    ['tenant_id' => $tenantModel->id, 'module' => 'chatbot', 'key' => $key],
                    ['value' => $value],
                );
            }
        }

        \Illuminate\Support\Facades\Cache::forget("tenant_settings:{$tenantModel->id}:chatbot");

        return response()->json(['success' => true, 'message' => 'Chatbot settings saved.']);
    }

    public function chatbotConversations(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');

        try {
            $chatService = app(\Modules\Chatbot\Services\ChatService::class);
            $filters = array_filter([
                'status' => $request->query('status'),
                'intent' => $request->query('intent'),
                'email'  => $request->query('email'),
                'limit'  => 100,
            ]);
            $data = $chatService->listConversations($tenantModel->id, $filters);
            $conversations = $data['conversations'] ?? [];
        } catch (\Throwable) {
            $conversations = [];
        }

        // Compute stats from conversations
        $convCollection = collect($conversations);
        $stats = [
            'total'     => $convCollection->count(),
            'active'    => $convCollection->where('status', 'active')->count(),
            'resolved'  => $convCollection->where('status', 'resolved')->count(),
            'escalated' => $convCollection->where('status', 'escalated')->count(),
        ];

        return view('tenant.pages.chatbot.conversations', [
            'conversations' => $conversations,
            'stats'         => $stats,
        ]);
    }

    public function chatbotAnalyticsDashboard(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');
        $days = (int) $request->query('days', 30);

        try {
            $chatService = app(\Modules\Chatbot\Services\ChatService::class);
            $analytics = $chatService->getAnalytics($tenantModel->id, $days);
        } catch (\Throwable) {
            $analytics = [
                'total_conversations' => 0, 'resolution_rate' => 0, 'escalation_rate' => 0,
                'avg_satisfaction' => null, 'intent_breakdown' => [],
            ];
        }

        return view('tenant.pages.chatbot.analytics', [
            'analytics' => $analytics,
            'days'      => $days,
        ]);
    }

    // ── Data Sync Settings ───────────────────
    public function datasyncSettings(Request $request, string $tenant): View
    {
        $tenantModel = $request->attributes->get('tenant');
        $registry    = app(\App\Services\SettingsRegistry::class);

        // Load all datasync settings for this tenant
        $settings = \App\Models\TenantSetting::where('tenant_id', $tenantModel->id)
            ->where('module', 'datasync')
            ->pluck('value', 'key')
            ->toArray();

        // Remote settings reported by Magento (stored during register/heartbeat)
        $remoteSettings = \App\Models\TenantSetting::where('tenant_id', $tenantModel->id)
            ->where('module', 'datasync_remote')
            ->pluck('value', 'key')
            ->toArray();

        // Get the first active connection for defaults
        $connection = \Modules\DataSync\Models\SyncConnection::where('tenant_id', $tenantModel->id)
            ->where('is_active', true)
            ->first();

        return view('tenant.pages.datasync.settings', [
            'settings'       => $settings,
            'remoteSettings' => $remoteSettings,
            'connection'     => $connection ?? (object) ['currency' => 'INR', 'locale' => 'en_US', 'store_url' => ''],
        ]);
    }

    public function datasyncSettingsSave(Request $request, string $tenant): \Illuminate\Http\JsonResponse
    {
        $tenantModel = $request->attributes->get('tenant');

        // All saveable setting keys
        $keys = [
            'brand_attribute', 'name_attribute', 'color_attribute', 'size_attribute',
            'custom_attributes', 'image_attribute',
            'currency_code', 'currency_symbol', 'locale',
            'sync_products', 'sync_categories', 'sync_orders', 'sync_customers',
            'sync_inventory', 'sync_brands',
            'search_brand_facet', 'search_category_facet', 'search_price_facet',
            'chatbot_enabled', 'chatbot_product_cards', 'search_autocomplete',
            'products_per_page', 'chatbot_max_products', 'price_display',
            'store_base_url', 'product_url_suffix', 'no_image_placeholder',
        ];

        foreach ($keys as $key) {
            $value = $request->input($key);
            if ($value !== null) {
                \App\Models\TenantSetting::updateOrCreate(
                    ['tenant_id' => $tenantModel->id, 'module' => 'datasync', 'key' => $key],
                    ['value' => $value],
                );
            }
        }

        // Clear cached settings
        \Illuminate\Support\Facades\Cache::forget("tenant_settings:{$tenantModel->id}:datasync");

        return response()->json(['success' => true, 'message' => 'Settings saved.']);
    }
}
