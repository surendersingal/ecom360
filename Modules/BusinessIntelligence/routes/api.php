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

    // Cross-Module Intelligence
    Route::get('intel/cross/marketing-attribution', [BiController::class, 'apiMarketingAttribution'])->name('bi.intel.cross.marketing');
    Route::get('intel/cross/search-revenue',        [BiController::class, 'apiSearchRevenue'])->name('bi.intel.cross.search');
    Route::get('intel/cross/chatbot-impact',        [BiController::class, 'apiChatbotImpact'])->name('bi.intel.cross.chatbot');
    Route::get('intel/cross/customer-360',          [BiController::class, 'apiCustomer360'])->name('bi.intel.cross.customer360');
});
