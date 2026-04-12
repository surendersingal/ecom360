<?php
/**
 * Cross-Module 50 Use Cases — Live Test Script
 * Boots Laravel and runs all 50 UC methods against real production data.
 *
 * Usage: php tests/cross_module_50_usecases.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Modules\BusinessIntelligence\Services\CrossModuleIntelService;

echo "\n" . str_repeat('═', 80) . "\n";
echo "  ecom360 — 50 Cross-Module Use Cases — Live Production Test\n";
echo str_repeat('═', 80) . "\n\n";

// Get tenant ID
$tenant = DB::table('tenants')->first();
if (!$tenant) { echo "❌ No tenant found. Exiting.\n"; exit(1); }
$tenantId = (int) $tenant->id;
echo "🏪 Tenant: #{$tenantId}\n\n";

$service = app(CrossModuleIntelService::class);

$tests = [
    // ── Existing 4 ────────────────────────────────────────────────
    ['uc' => '01', 'name' => 'Marketing Attribution',          'method' => fn() => $service->marketingAttribution($tenantId),     'summary' => fn($d) => sprintf('%d campaigns, attributed ₹%s (%.1f%%)', count($d['campaigns']??[]), number_format($d['total_campaign_revenue']??0), $d['attributed_pct']??0)],
    ['uc' => '02', 'name' => 'Search Revenue Correlation',     'method' => fn() => $service->searchRevenue($tenantId),            'summary' => fn($d) => sprintf('%d searches, %.1f%% CTR, %d top queries', $d['summary']['total_searches']??0, $d['summary']['click_rate']??0, count($d['top_queries']??[]))],
    ['uc' => '03', 'name' => 'Chatbot Conversion Impact',      'method' => fn() => $service->chatbotImpact($tenantId),            'summary' => fn($d) => sprintf('%d conversations, %.1f%% resolved, AOV lift %.1f%%', $d['summary']['total_conversations']??0, $d['summary']['resolution_rate']??0, $d['revenue_impact']['aov_lift_pct']??0)],
    ['uc' => '04', 'name' => 'Customer 360 (sample)',          'method' => fn() => $service->customer360($tenantId, (function() use ($tenantId) { $o = DB::connection('mongodb')->table('synced_orders')->where('tenant_id', $tenantId)->where('customer_email', '!=', null)->first(['customer_email']); return $o['customer_email'] ?? 'test@example.com'; })()), 'summary' => fn($d) => sprintf('Orders: %d, Revenue: ₹%s', $d['orders']['total']??0, number_format($d['orders']['revenue']??0))],
    // ── Group A: Search × Orders ───────────────────────────────────
    ['uc' => '05', 'name' => 'Search to Order Funnel',         'method' => fn() => $service->UC05_searchToOrderFunnel($tenantId),         'summary' => fn($d) => sprintf('%d queries analyzed, top revenue query: %s', count($d['queries']??[]), $d['queries'][0]['query']??'N/A')],
    ['uc' => '06', 'name' => 'Zero-Result Opportunities',      'method' => fn() => $service->UC06_zeroResultOpportunities($tenantId),      'summary' => fn($d) => sprintf('%d zero-result opportunities found', count($d['opportunities']??[]))],
    ['uc' => '07', 'name' => 'Abandoned Search Recovery',      'method' => fn() => $service->UC07_abandonedSearchRecovery($tenantId),      'summary' => fn($d) => sprintf('%d customers with abandoned searches', $d['total_at_risk']??0)],
    ['uc' => '08', 'name' => 'Search Seasonality Matrix',      'method' => fn() => $service->UC08_searchSeasonality($tenantId),            'summary' => fn($d) => sprintf('%d time slots mapped, peak: DOW %d hour %d', count($d['matrix']??[]), $d['peak_slot']['dow']??0, $d['peak_slot']['hour']??0)],
    ['uc' => '09', 'name' => 'Category Search-to-Sales Gap',   'method' => fn() => $service->UC09_categorySearchToSalesGap($tenantId),    'summary' => fn($d) => sprintf('%d category gaps, top: %s', count($d['gaps']??[]), $d['gaps'][0]['category']??'N/A')],
    // ── Group B: Chatbot × Orders ──────────────────────────────────
    ['uc' => '10', 'name' => 'Chatbot to Checkout Path',       'method' => fn() => $service->UC10_chatbotToCheckoutPath($tenantId),        'summary' => fn($d) => sprintf('%d chatbot users, 24h orders: %d, 30d orders: %d', $d['chatbot_users']??0, $d['conversion_windows']['24h']['count']??0, $d['conversion_windows']['30d']['count']??0)],
    ['uc' => '11', 'name' => 'Chatbot Abandonment Rate',       'method' => fn() => $service->UC11_chatbotAbandonment($tenantId),           'summary' => fn($d) => sprintf('%d high-intent sessions, %.1f%% abandoned', $d['total_high_intent_sessions']??0, $d['abandonment_rate']??0)],
    ['uc' => '12', 'name' => 'Chatbot Product Complaints',     'method' => fn() => $service->UC12_chatbotProductComplaints($tenantId),     'summary' => fn($d) => sprintf('%d intent types, top keyword: %s', count($d['by_intent']??[]), array_key_first($d['product_keywords']??['N/A'=>0])??'N/A')],
    ['uc' => '13', 'name' => 'Chatbot Upsell Success',         'method' => fn() => $service->UC13_chatbotUpsellSuccess($tenantId),         'summary' => fn($d) => sprintf('%d rec sessions, %.1f%% upsell rate, ₹%s upsell revenue', $d['recommendation_sessions']??0, $d['upsell_conversion_rate']??0, number_format($d['upsell_revenue']??0))],
    ['uc' => '14', 'name' => 'Chatbot Sentiment vs Orders',    'method' => fn() => $service->UC14_chatbotSentimentVsOrders($tenantId),    'summary' => fn($d) => sprintf('Happy reorder: %.1f%%, Unhappy: %.1f%%', $d['by_sentiment']['happy']['reorder_rate']??0, $d['by_sentiment']['unhappy']['reorder_rate']??0)],
    // ── Group C: Marketing × Behavior ─────────────────────────────
    ['uc' => '15', 'name' => 'Campaign to Search Behavior',    'method' => fn() => $service->UC15_campaignToSearchBehavior($tenantId),    'summary' => fn($d) => sprintf('%d campaigns analyzed for post-send search', count($d['campaigns']??[]))],
    ['uc' => '16', 'name' => 'Segment Search Affinity',        'method' => fn() => $service->UC16_segmentSearchAffinity($tenantId),        'summary' => fn($d) => sprintf('%d segments mapped to search terms', count($d['by_segment']??[]))],
    ['uc' => '17', 'name' => 'Campaign Unsubscribe Risk',      'method' => fn() => $service->UC17_campaignUnsubscribeRisk($tenantId),      'summary' => fn($d) => sprintf('%d unsubscribers, %d had orders, ₹%s lost', $d['total_unsubscribed']??0, $d['had_orders']??0, number_format($d['total_revenue_lost']??0))],
    ['uc' => '18', 'name' => 'Flow Trigger Effectiveness',     'method' => fn() => $service->UC18_flowTriggerEffectiveness($tenantId),    'summary' => fn($d) => sprintf('%d flows analyzed', count($d['flows']??[]))],
    ['uc' => '19', 'name' => 'Campaign Timing Optimizer',      'method' => fn() => $service->UC19_campaignTimingOptimizer($tenantId),      'summary' => fn($d) => sprintf('%d timing slots, best rate: %.2f%%', count($d['best_times']??[]), $d['best_times'][0]['conversion_rate']??0)],
    // ── Group D: Inventory × Demand ───────────────────────────────
    ['uc' => '20', 'name' => 'Demand Forecast by Search',      'method' => fn() => $service->UC20_demandForecastBySearch($tenantId),      'summary' => fn($d) => sprintf('%d products forecast, %d stockout risks', count($d['demand_forecast']??[]), count($d['stockout_risks']??[]))],
    ['uc' => '21', 'name' => 'Out-of-Stock Revenue Loss',      'method' => fn() => $service->UC21_outOfStockRevenueLoss($tenantId),      'summary' => fn($d) => sprintf('%d OOS products, ₹%s estimated loss', $d['out_of_stock_products']??0, number_format($d['total_estimated_loss']??0))],
    ['uc' => '22', 'name' => 'Category Trend Surface',         'method' => fn() => $service->UC22_categoryTrendSurface($tenantId),        'summary' => fn($d) => sprintf('%d categories, rising demand: %d', count($d['trends']??[]), count(array_filter($d['trends']??[], fn($r) => ($r['search_trend_pct']??0) > 10)))],
    ['uc' => '23', 'name' => 'Bundle Opportunity Detector',    'method' => fn() => $service->UC23_bundleOpportunity($tenantId),            'summary' => fn($d) => sprintf('%d bundle pairs, top: %s (%d co-purchases)', count($d['bundle_opportunities']??[]), $d['bundle_opportunities'][0]['products']??'N/A', $d['bundle_opportunities'][0]['co_purchase_count']??0)],
    ['uc' => '24', 'name' => 'Brand Search Share',             'method' => fn() => $service->UC24_brandSearchShare($tenantId),            'summary' => fn($d) => sprintf('%d brands mapped, top search share: %s', count($d['brands']??[]), $d['brands'][0]['brand']??'N/A')],
    // ── Group E: Customer Intelligence ────────────────────────────
    ['uc' => '25', 'name' => 'High-Value Customer Journey',    'method' => fn() => $service->UC25_highValueCustomerJourney($tenantId),    'summary' => fn($d) => sprintf('%d VIP customers, top LTV: ₹%s', count($d['vip_customers']??[]), number_format($d['vip_customers'][0]['ltv']??0))],
    ['uc' => '26', 'name' => 'Churn Risk + Chat Signals',      'method' => fn() => $service->UC26_churnRiskWithChatSignals($tenantId),    'summary' => fn($d) => sprintf('%d high-risk, %d with chat signals', $d['total_high_risk']??0, $d['with_bad_chat_signals']??0)],
    ['uc' => '27', 'name' => 'Dormant Customer Search History', 'method' => fn() => $service->UC27_dormantCustomerSearchHistory($tenantId), 'summary' => fn($d) => sprintf('%d browsing-but-not-buying customers', $d['total_browsing_not_buying']??0)],
    ['uc' => '28', 'name' => 'New Customer First-Touch ROI',   'method' => fn() => $service->UC28_newCustomerFirstTouchROI($tenantId),    'summary' => fn($d) => sprintf('Search: %d custs (₹%s avg), Chatbot: %d custs (₹%s avg)', $d['first_touch_roi']['organic_search']['count']??0, number_format($d['first_touch_roi']['organic_search']['avg_revenue']??0), $d['first_touch_roi']['chatbot_assisted']['count']??0, number_format($d['first_touch_roi']['chatbot_assisted']['avg_revenue']??0))],
    ['uc' => '29', 'name' => 'VIP Behavior Profile',           'method' => fn() => $service->UC29_vipBehaviorProfile($tenantId),          'summary' => fn($d) => sprintf('%d VIP profiles, avg reorder interval: %.0f days', count($d['vip_profiles']??[]), collect($d['vip_profiles']??[])->avg('avg_days_between_orders')??0)],
    // ── Group F: Operational Excellence ───────────────────────────
    ['uc' => '30', 'name' => 'Order Issue Heatmap',            'method' => fn() => $service->UC30_orderIssueHeatmap($tenantId),            'summary' => fn($d) => sprintf('%d heatmap slots, peak: DOW %d hr %d (%d complaints)', count($d['heatmap']??[]), $d['peak_complaint_slot']['dow']??0, $d['peak_complaint_slot']['hour']??0, $d['peak_complaint_slot']['complaints']??0)],
    ['uc' => '31', 'name' => 'Return Rate by Search Query',    'method' => fn() => $service->UC31_returnRateBySearchQuery($tenantId),    'summary' => fn($d) => sprintf('%d queries analyzed, highest return: %s (%.1f%%)', count($d['return_rate_by_query']??[]), $d['return_rate_by_query'][0]['query']??'N/A', $d['return_rate_by_query'][0]['return_rate']??0)],
    ['uc' => '32', 'name' => 'Payment Failure Recovery',       'method' => fn() => $service->UC32_paymentFailureRecovery($tenantId),      'summary' => fn($d) => sprintf('%d failures, %.1f%% recovery rate, ₹%s recovered', $d['payment_failures']??0, $d['recovery_rate']??0, number_format($d['recovered_revenue']??0))],
    ['uc' => '33', 'name' => 'Fulfillment Delay Impact',       'method' => fn() => $service->UC33_fulfillmentDelayImpact($tenantId),      'summary' => fn($d) => sprintf('%d weeks analyzed, avg complaint rate: %.1f%%', count($d['weekly_correlation']??[]), collect($d['weekly_correlation']??[])->avg('complaint_rate')??0)],
    ['uc' => '34', 'name' => 'Peak Demand Readiness',          'method' => fn() => $service->UC34_peakDemandReadiness($tenantId),          'summary' => fn($d) => sprintf('Readiness score: %d/100, %d peak hours, %.1f%% in-stock', $d['readiness_score']??0, count($d['peak_hours']??[]), $d['inventory_health']['in_stock_rate']??0)],
    // ── Group G: Revenue Optimization ─────────────────────────────
    ['uc' => '35', 'name' => 'Revenue by Acquisition Channel', 'method' => fn() => $service->UC35_revenueByAcquisitionChannel($tenantId), 'summary' => fn($d) => sprintf('Search: ₹%s, Chatbot: ₹%s, Campaign: ₹%s, Direct: ₹%s', number_format($d['by_channel']['organic_search']['revenue']??0), number_format($d['by_channel']['chatbot_assisted']['revenue']??0), number_format($d['by_channel']['campaign']['revenue']??0), number_format($d['by_channel']['direct']['revenue']??0))],
    ['uc' => '36', 'name' => 'Discount Sensitivity by Segment','method' => fn() => $service->UC36_discountSensitivityBySegment($tenantId),'summary' => fn($d) => sprintf('%d segments, Champions coupon rate: %.1f%%', count($d['by_segment']??[]), $d['by_segment']['Champions']['coupon_rate']??0)],
    ['uc' => '37', 'name' => 'LTV by First Product (Gateway)', 'method' => fn() => $service->UC37_ltvByFirstProduct($tenantId),           'summary' => fn($d) => sprintf('%d gateway products, top avg LTV: ₹%s via %s', count($d['gateway_products']??[]), number_format($d['gateway_products'][0]['avg_ltv']??0), $d['gateway_products'][0]['product']??'N/A')],
    ['uc' => '38', 'name' => 'Cross-Sell Gap Analysis',        'method' => fn() => $service->UC38_crossSellGap($tenantId),                'summary' => fn($d) => sprintf('%d cross-sell opportunities, top: %s', count($d['cross_sell_opportunities']??[]), $d['cross_sell_opportunities'][0]['pair']??'N/A')],
    // ── Group H: Real-Time ─────────────────────────────────────────
    ['uc' => '39', 'name' => 'Live Cart Abandonment',          'method' => fn() => $service->UC39_liveCartAbandonment($tenantId),          'summary' => fn($d) => sprintf('%d abandoned carts, %d rescued, ₹%s at risk', $d['abandoned_carts']??0, $d['chatbot_rescued']??0, number_format($d['revenue_at_risk']??0))],
    ['uc' => '40', 'name' => 'Rage Click Order Loss',          'method' => fn() => $service->UC40_rageClickToOrderLoss($tenantId),         'summary' => fn($d) => sprintf('%d rage sessions, %.2f%% vs %.2f%% conv, ₹%s lost', $d['rage_click_sessions']??0, $d['rage_conversion_rate']??0, $d['normal_conversion_rate']??0, number_format($d['estimated_revenue_loss']??0))],
    ['uc' => '41', 'name' => 'Session Intent Scoring',         'method' => fn() => $service->UC41_sessionIntentScoring($tenantId),         'summary' => fn($d) => sprintf('%d active sessions, top intent score: %d', $d['total_active_sessions']??0, $d['high_intent_sessions'][0]['intent_score']??0)],
    ['uc' => '42', 'name' => 'Real-Time Churn Signals',        'method' => fn() => $service->UC42_realTimeChurnSignals($tenantId),         'summary' => fn($d) => sprintf('%d at risk, %d in watchlist', $d['total_at_risk']??0, count($d['churn_watchlist']??[]))],
    ['uc' => '43', 'name' => 'Proactive Reorder Signals',      'method' => fn() => $service->UC43_proactiveReorderSignals($tenantId),      'summary' => fn($d) => sprintf('%d customers approaching reorder time', $d['total']??0)],
    // ── Group I: Market Intelligence ───────────────────────────────
    ['uc' => '44', 'name' => 'Search Gap Analysis',            'method' => fn() => $service->UC44_searchGapAnalysis($tenantId),            'summary' => fn($d) => sprintf('%d zero-result queries, %d true catalog gaps', count($d['gaps']??[]), count($d['true_gaps']??[]))],
    ['uc' => '45', 'name' => 'New Product Launch Readiness',   'method' => fn() => $service->UC45_newProductLaunchReadiness($tenantId),    'summary' => fn($d) => sprintf('%d new products, top readiness: %d/100 (%s)', count($d['new_products']??[]), $d['new_products'][0]['launch_readiness_score']??0, $d['new_products'][0]['product']??'N/A')],
    ['uc' => '46', 'name' => 'Campaign Cannibalization',       'method' => fn() => $service->UC46_campaignCannibalizationDetector($tenantId), 'summary' => fn($d) => sprintf('Email: %.2f%%, SMS: %.2f%%, Both: %.2f%%, Lift: %.1f%%, Risk: %s', $d['only_email']['rate']??0, $d['only_sms']['rate']??0, $d['both_channels']['rate']??0, $d['incremental_lift_pct']??0, $d['is_cannibalization_risk'] ? 'YES' : 'NO')],
    ['uc' => '47', 'name' => 'Price-Search Conversion Elasticity','method' => fn() => $service->UC47_priceSearchConversionElasticity($tenantId),'summary' => fn($d) => sprintf('%d discounted products analyzed', count($d['price_elasticity']??[]))],
    ['uc' => '48', 'name' => 'Margin-Optimized Search Ranking', 'method' => fn() => $service->UC48_marginOptimizedSearchRanking($tenantId),'summary' => fn($d) => sprintf('%d queries, %d low-margin leakage alerts', count($d['search_margin_analysis']??[]), count(array_filter($d['search_margin_analysis']??[], fn($r) => ($r['margin_flag']??'') === 'low_margin_leakage')))],
    // ── Group J: USP Showcase ──────────────────────────────────────
    ['uc' => '49', 'name' => '🏆 Omni-Channel Conversion Funnel','method' => fn() => $service->UC49_omniChannelConversionFunnel($tenantId),'summary' => fn($d) => sprintf('%d-stage funnel, overall conv: %.4f%%, %d modules', count($d['funnel']??[]), $d['overall_conversion_rate']??0, count($d['modules']??[]))],
    ['uc' => '50', 'name' => '🏆 Store Health Score',           'method' => fn() => $service->UC50_storeHealthScore($tenantId),            'summary' => fn($d) => sprintf('Score: %.1f/100 (Grade: %s) | Search:%.1f Chatbot:%.1f Campaign:%.1f Inventory:%.1f Retention:%.1f', $d['store_health_score']??0, $d['grade']??'?', $d['breakdown']['search_quality']['score']??0, $d['breakdown']['chatbot_resolution']['score']??0, $d['breakdown']['campaign_roi']['score']??0, $d['breakdown']['inventory_health']['score']??0, $d['breakdown']['customer_retention']['score']??0)],
];

$pass = 0; $fail = 0; $results = [];
foreach ($tests as $test) {
    $label = "UC{$test['uc']}: {$test['name']}";
    $start = microtime(true);
    try {
        $data = ($test['method'])();
        $ms   = round((microtime(true) - $start) * 1000);
        $summary = ($test['summary'])($data);
        echo "  ✅ UC{$test['uc']} [{$ms}ms] {$test['name']}\n     └─ {$summary}\n";
        $pass++;
        $results[] = ['uc' => $test['uc'], 'status' => 'PASS', 'ms' => $ms, 'summary' => $summary];
    } catch (\Throwable $e) {
        $ms = round((microtime(true) - $start) * 1000);
        echo "  ❌ UC{$test['uc']} [{$ms}ms] {$test['name']}\n     └─ ERROR: " . $e->getMessage() . "\n";
        $fail++;
        $results[] = ['uc' => $test['uc'], 'status' => 'FAIL', 'ms' => $ms, 'summary' => $e->getMessage()];
    }
}

echo "\n" . str_repeat('═', 80) . "\n";
echo "  RESULTS: {$pass}/50 passed, {$fail} failed\n";
echo str_repeat('═', 80) . "\n\n";

if ($fail > 0) {
    echo "Failed Use Cases:\n";
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') echo "  ❌ UC{$r['uc']}: {$r['summary']}\n";
    }
    echo "\n";
}

$avgMs = round(array_sum(array_column($results, 'ms')) / count($results));
echo "  Avg response time: {$avgMs}ms\n";
echo "  Pass rate: " . round($pass / 50 * 100, 0) . "%\n\n";
