<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\BusinessIntelligence\Http\Controllers\Api\ReportController;
use Modules\BusinessIntelligence\Http\Controllers\Api\DashboardController;
use Modules\BusinessIntelligence\Http\Controllers\Api\KpiController;
use Modules\BusinessIntelligence\Http\Controllers\Api\AlertController;
use Modules\BusinessIntelligence\Http\Controllers\Api\ExportController;
use Modules\BusinessIntelligence\Http\Controllers\Api\InsightsController;
use App\Http\Controllers\Tenant\BiController;

Route::middleware(['auth:sanctum', 'tenant.permission:business_intelligence.view'])->prefix('v1/bi')->group(function () {

    // Reports
    Route::apiResource('reports', ReportController::class)->names('bi.reports');
    Route::post('reports/{report}/execute', [ReportController::class, 'execute'])->name('bi.reports.execute');
    Route::get('reports/meta/templates', [ReportController::class, 'templates'])->name('bi.reports.templates');
    Route::post('reports/from-template', [ReportController::class, 'createFromTemplate'])->name('bi.reports.from-template');

    // Dashboards
    Route::apiResource('dashboards', DashboardController::class)->names('bi.dashboards');
    Route::post('dashboards/{dashboard}/duplicate', [DashboardController::class, 'duplicate'])->name('bi.dashboards.duplicate');

    // KPIs
    Route::apiResource('kpis', KpiController::class)->names('bi.kpis');
    Route::post('kpis/refresh', [KpiController::class, 'refresh'])->name('bi.kpis.refresh');
    Route::post('kpis/defaults', [KpiController::class, 'defaults'])->name('bi.kpis.defaults');

    // Alerts
    Route::apiResource('alerts', AlertController::class)->names('bi.alerts');
    Route::get('alerts/{alert}/history', [AlertController::class, 'history'])->name('bi.alerts.history');
    Route::post('alerts/history/{alertHistory}/acknowledge', [AlertController::class, 'acknowledge'])->name('bi.alerts.acknowledge');
    Route::post('alerts/evaluate', [AlertController::class, 'evaluate'])->name('bi.alerts.evaluate');

    // Exports
    Route::apiResource('exports', ExportController::class)->except(['update'])->names('bi.exports');
    Route::get('exports/{export}/download', [ExportController::class, 'download'])->name('bi.exports.download');

    // Insights (predictions, benchmarks, ad-hoc queries)
    Route::get('insights/predictions', [InsightsController::class, 'predictions'])->name('bi.insights.predictions');
    Route::post('insights/predictions/generate', [InsightsController::class, 'generatePredictions'])->name('bi.insights.predictions.generate');
    Route::get('insights/benchmarks', [InsightsController::class, 'benchmarks'])->name('bi.insights.benchmarks');
    Route::post('insights/query', [InsightsController::class, 'query'])->name('bi.insights.query');
    Route::get('insights/fields/{source}', [InsightsController::class, 'availableFields'])->name('bi.insights.fields');

    /* ─── BI Intelligence Endpoints ─── */

    // Revenue Intelligence
    Route::get('intel/revenue/command-center',   [BiController::class, 'apiRevenueCommandCenter'])->name('bi.intel.revenue.command-center');
    Route::get('intel/revenue/by-hour',          [BiController::class, 'apiRevenueByHour'])->name('bi.intel.revenue.by-hour');
    Route::get('intel/revenue/by-day',           [BiController::class, 'apiRevenueByDay'])->name('bi.intel.revenue.by-day');
    Route::get('intel/revenue/trend',            [BiController::class, 'apiRevenueTrend'])->name('bi.intel.revenue.trend');
    Route::get('intel/revenue/breakdown',        [BiController::class, 'apiRevenueBreakdown'])->name('bi.intel.revenue.breakdown');
    Route::get('intel/revenue/margin',           [BiController::class, 'apiRevenueMargin'])->name('bi.intel.revenue.margin');
    Route::get('intel/revenue/top-performers',   [BiController::class, 'apiRevenueTopPerformers'])->name('bi.intel.revenue.top-performers');

    // Product Intelligence
    Route::get('intel/products/leaderboard',     [BiController::class, 'apiProductLeaderboard'])->name('bi.intel.products.leaderboard');
    Route::get('intel/products/stars',           [BiController::class, 'apiProductStars'])->name('bi.intel.products.stars');
    Route::get('intel/products/category-matrix', [BiController::class, 'apiCategoryMatrix'])->name('bi.intel.products.category-matrix');
    Route::get('intel/products/pareto',          [BiController::class, 'apiParetoAnalysis'])->name('bi.intel.products.pareto');

    // Customer Intelligence
    Route::get('intel/customers/overview',       [BiController::class, 'apiCustomerOverview'])->name('bi.intel.customers.overview');
    Route::get('intel/customers/acquisition',    [BiController::class, 'apiCustomerAcquisition'])->name('bi.intel.customers.acquisition');
    Route::get('intel/customers/geo',            [BiController::class, 'apiCustomerGeo'])->name('bi.intel.customers.geo');
    Route::get('intel/customers/cohort',         [BiController::class, 'apiCohortRetention'])->name('bi.intel.customers.cohort');
    Route::get('intel/customers/value-dist',     [BiController::class, 'apiValueDistribution'])->name('bi.intel.customers.value-dist');
    Route::get('intel/customers/new-vs-returning', [BiController::class, 'apiNewVsReturning'])->name('bi.intel.customers.new-vs-returning');

    // Operations Intelligence
    Route::get('intel/operations/pipeline',      [BiController::class, 'apiOrderPipeline'])->name('bi.intel.operations.pipeline');
    Route::get('intel/operations/daily-volume',  [BiController::class, 'apiDailyOrderVolume'])->name('bi.intel.operations.daily-volume');
    Route::get('intel/operations/heatmap',       [BiController::class, 'apiHeatmap'])->name('bi.intel.operations.heatmap');
    Route::get('intel/operations/coupons',       [BiController::class, 'apiCouponIntelligence'])->name('bi.intel.operations.coupons');
    Route::get('intel/operations/payments',      [BiController::class, 'apiPaymentAnalysis'])->name('bi.intel.operations.payments');

    // Cross-Module Intelligence (original 4)
    Route::get('intel/cross/marketing-attribution', [BiController::class, 'apiMarketingAttribution'])->name('bi.intel.cross.marketing');
    Route::get('intel/cross/search-revenue',        [BiController::class, 'apiSearchRevenue'])->name('bi.intel.cross.search');
    Route::get('intel/cross/chatbot-impact',        [BiController::class, 'apiChatbotImpact'])->name('bi.intel.cross.chatbot');
    Route::get('intel/cross/customer-360',          [BiController::class, 'apiCustomer360'])->name('bi.intel.cross.customer360');

    // ─── 50 Cross-Module Use Cases ────────────────────────────────
    // Group A: Search × Orders
    Route::get('intel/cross/search-to-order-funnel',        [BiController::class, 'apiUC05'])->name('bi.intel.cross.uc05');
    Route::get('intel/cross/zero-result-opportunities',     [BiController::class, 'apiUC06'])->name('bi.intel.cross.uc06');
    Route::get('intel/cross/abandoned-search-recovery',     [BiController::class, 'apiUC07'])->name('bi.intel.cross.uc07');
    Route::get('intel/cross/search-seasonality',            [BiController::class, 'apiUC08'])->name('bi.intel.cross.uc08');
    Route::get('intel/cross/category-search-sales-gap',     [BiController::class, 'apiUC09'])->name('bi.intel.cross.uc09');
    // Group B: Chatbot × Orders
    Route::get('intel/cross/chatbot-checkout-path',         [BiController::class, 'apiUC10'])->name('bi.intel.cross.uc10');
    Route::get('intel/cross/chatbot-abandonment',           [BiController::class, 'apiUC11'])->name('bi.intel.cross.uc11');
    Route::get('intel/cross/chatbot-product-complaints',    [BiController::class, 'apiUC12'])->name('bi.intel.cross.uc12');
    Route::get('intel/cross/chatbot-upsell-success',        [BiController::class, 'apiUC13'])->name('bi.intel.cross.uc13');
    Route::get('intel/cross/chatbot-sentiment-vs-orders',   [BiController::class, 'apiUC14'])->name('bi.intel.cross.uc14');
    // Group C: Marketing × Behavior
    Route::get('intel/cross/campaign-to-search-behavior',   [BiController::class, 'apiUC15'])->name('bi.intel.cross.uc15');
    Route::get('intel/cross/segment-search-affinity',       [BiController::class, 'apiUC16'])->name('bi.intel.cross.uc16');
    Route::get('intel/cross/campaign-unsubscribe-risk',     [BiController::class, 'apiUC17'])->name('bi.intel.cross.uc17');
    Route::get('intel/cross/flow-trigger-effectiveness',    [BiController::class, 'apiUC18'])->name('bi.intel.cross.uc18');
    Route::get('intel/cross/campaign-timing-optimizer',     [BiController::class, 'apiUC19'])->name('bi.intel.cross.uc19');
    // Group D: Inventory × Demand
    Route::get('intel/cross/demand-forecast-by-search',     [BiController::class, 'apiUC20'])->name('bi.intel.cross.uc20');
    Route::get('intel/cross/out-of-stock-revenue-loss',     [BiController::class, 'apiUC21'])->name('bi.intel.cross.uc21');
    Route::get('intel/cross/category-trend-surface',        [BiController::class, 'apiUC22'])->name('bi.intel.cross.uc22');
    Route::get('intel/cross/bundle-opportunity',            [BiController::class, 'apiUC23'])->name('bi.intel.cross.uc23');
    Route::get('intel/cross/brand-search-share',            [BiController::class, 'apiUC24'])->name('bi.intel.cross.uc24');
    // Group E: Customer Intelligence
    Route::get('intel/cross/high-value-customer-journey',   [BiController::class, 'apiUC25'])->name('bi.intel.cross.uc25');
    Route::get('intel/cross/churn-risk-chat-signals',       [BiController::class, 'apiUC26'])->name('bi.intel.cross.uc26');
    Route::get('intel/cross/dormant-customer-search',       [BiController::class, 'apiUC27'])->name('bi.intel.cross.uc27');
    Route::get('intel/cross/new-customer-first-touch-roi',  [BiController::class, 'apiUC28'])->name('bi.intel.cross.uc28');
    Route::get('intel/cross/vip-behavior-profile',          [BiController::class, 'apiUC29'])->name('bi.intel.cross.uc29');
    // Group F: Operational Excellence
    Route::get('intel/cross/order-issue-heatmap',           [BiController::class, 'apiUC30'])->name('bi.intel.cross.uc30');
    Route::get('intel/cross/return-rate-by-search-query',   [BiController::class, 'apiUC31'])->name('bi.intel.cross.uc31');
    Route::get('intel/cross/payment-failure-recovery',      [BiController::class, 'apiUC32'])->name('bi.intel.cross.uc32');
    Route::get('intel/cross/fulfillment-delay-impact',      [BiController::class, 'apiUC33'])->name('bi.intel.cross.uc33');
    Route::get('intel/cross/peak-demand-readiness',         [BiController::class, 'apiUC34'])->name('bi.intel.cross.uc34');
    // Group G: Revenue Optimization
    Route::get('intel/cross/revenue-by-acquisition-channel',[BiController::class, 'apiUC35'])->name('bi.intel.cross.uc35');
    Route::get('intel/cross/discount-sensitivity-by-segment',[BiController::class, 'apiUC36'])->name('bi.intel.cross.uc36');
    Route::get('intel/cross/ltv-by-first-product',          [BiController::class, 'apiUC37'])->name('bi.intel.cross.uc37');
    Route::get('intel/cross/cross-sell-gap',                [BiController::class, 'apiUC38'])->name('bi.intel.cross.uc38');
    // Group H: Real-Time Intervention
    Route::get('intel/cross/live-cart-abandonment',         [BiController::class, 'apiUC39'])->name('bi.intel.cross.uc39');
    Route::get('intel/cross/rage-click-order-loss',         [BiController::class, 'apiUC40'])->name('bi.intel.cross.uc40');
    Route::get('intel/cross/session-intent-scoring',        [BiController::class, 'apiUC41'])->name('bi.intel.cross.uc41');
    Route::get('intel/cross/realtime-churn-signals',        [BiController::class, 'apiUC42'])->name('bi.intel.cross.uc42');
    Route::get('intel/cross/proactive-reorder-signals',     [BiController::class, 'apiUC43'])->name('bi.intel.cross.uc43');
    // Group I: Market Intelligence
    Route::get('intel/cross/search-gap-analysis',           [BiController::class, 'apiUC44'])->name('bi.intel.cross.uc44');
    Route::get('intel/cross/new-product-launch-readiness',  [BiController::class, 'apiUC45'])->name('bi.intel.cross.uc45');
    Route::get('intel/cross/campaign-cannibalization',      [BiController::class, 'apiUC46'])->name('bi.intel.cross.uc46');
    Route::get('intel/cross/price-conversion-elasticity',   [BiController::class, 'apiUC47'])->name('bi.intel.cross.uc47');
    Route::get('intel/cross/margin-optimized-search',       [BiController::class, 'apiUC48'])->name('bi.intel.cross.uc48');
    // Group J: USP Showcase
    Route::get('intel/cross/omni-channel-funnel',           [BiController::class, 'apiUC49'])->name('bi.intel.cross.uc49');
    Route::get('intel/cross/store-health-score',            [BiController::class, 'apiUC50'])->name('bi.intel.cross.uc50');
});
