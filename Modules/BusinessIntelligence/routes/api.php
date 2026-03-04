<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\BusinessIntelligence\Http\Controllers\Api\ReportController;
use Modules\BusinessIntelligence\Http\Controllers\Api\DashboardController;
use Modules\BusinessIntelligence\Http\Controllers\Api\KpiController;
use Modules\BusinessIntelligence\Http\Controllers\Api\AlertController;
use Modules\BusinessIntelligence\Http\Controllers\Api\ExportController;
use Modules\BusinessIntelligence\Http\Controllers\Api\InsightsController;

Route::middleware(['auth:sanctum'])->prefix('v1/bi')->group(function () {

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
});
