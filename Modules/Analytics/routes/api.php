<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Analytics\Http\Controllers\AnalyticsApiController;
use Modules\Analytics\Http\Controllers\AnalyticsController;
use Modules\Analytics\Http\Controllers\AnalyticsReportController;
use Modules\Analytics\Http\Controllers\IngestionController;
use Modules\Analytics\Http\Controllers\PublicIngestionController;
use Modules\Analytics\Http\Controllers\AdvancedAnalyticsController;
use Modules\Analytics\Http\Middleware\ValidateTrackingApiKey;

/*
 *--------------------------------------------------------------------------
 * API Routes — Analytics Module
 *--------------------------------------------------------------------------
 *
 * Enterprise REST API for the Ecom360 Analytics platform.
 *
 * Ingestion:  POST /api/v1/analytics/ingest
 * Report:     GET  /api/v1/analytics/report
 *
 * Analytics API (all GET unless noted):
 *   /api/v1/analytics/overview          Dashboard KPI overview
 *   /api/v1/analytics/traffic           Traffic & event stats
 *   /api/v1/analytics/revenue           Revenue analytics
 *   /api/v1/analytics/products          Product analytics
 *   /api/v1/analytics/sessions          Session & engagement metrics
 *   /api/v1/analytics/funnel            Conversion funnel
 *   /api/v1/analytics/customers         Customer & RFM analytics
 *   /api/v1/analytics/geographic        Geo & device analytics
 *   /api/v1/analytics/cohorts           Cohort retention analysis
 *   /api/v1/analytics/campaigns         Campaign & UTM analytics
 *   /api/v1/analytics/realtime          Real-time active metrics
 *   /api/v1/analytics/page-visits       Page-level analytics
 *   /api/v1/analytics/categories        Category analytics
 *   /api/v1/analytics/export            Raw event data export
 *
 * Custom Events:
 *   POST /api/v1/analytics/events/custom                  Track custom event
 *   GET  /api/v1/analytics/events/custom/definitions      List definitions
 *   POST /api/v1/analytics/events/custom/definitions      Create definition
 *
 * Public SDK Endpoints (API key auth via X-Ecom360-Key header):
 *   POST    /api/v1/collect                                Single event
 *   POST    /api/v1/collect/batch                          Batched events (max 50)
 *   OPTIONS /api/v1/collect                                CORS preflight
 */

// ─── Public SDK Routes (API Key auth, no Sanctum) ────────────────────
Route::prefix('v1')->middleware([ValidateTrackingApiKey::class])->group(function () {
    Route::post('collect', [PublicIngestionController::class, 'collect'])
        ->middleware('throttle:300,1')
        ->name('analytics.public.collect');

    Route::post('collect/batch', [PublicIngestionController::class, 'batch'])
        ->middleware('throttle:60,1')
        ->name('analytics.public.collect.batch');
});

// CORS preflight (no auth needed).
Route::options('v1/collect', [PublicIngestionController::class, 'preflight']);
Route::options('v1/collect/batch', [PublicIngestionController::class, 'preflight']);

// ─── Authenticated Routes (Sanctum) ──────────────────────────────────

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    // Legacy resource (kept for backwards compatibility)
    // Constrain to numeric IDs so it doesn't swallow named routes like /analytics/overview
    Route::apiResource('analytics', AnalyticsController::class)
        ->where(['analytic' => '[0-9]+'])
        ->names('analytics');

    // Ingestion endpoint (with rate limiting)
    Route::post('analytics/ingest', IngestionController::class)
        ->middleware('throttle:120,1')
        ->name('analytics.ingest');

    // Report endpoint
    Route::get('analytics/report', AnalyticsReportController::class)->name('analytics.report');

    // ─── Enterprise Analytics API ─────────────────────────────────────
    Route::prefix('analytics')->group(function () {
        // Dashboard & overview
        Route::get('overview', [AnalyticsApiController::class, 'overview'])->name('analytics.api.overview');
        Route::get('traffic', [AnalyticsApiController::class, 'traffic'])->name('analytics.api.traffic');
        Route::get('realtime', [AnalyticsApiController::class, 'realtime'])->name('analytics.api.realtime');

        // Revenue & Products
        Route::get('revenue', [AnalyticsApiController::class, 'revenue'])->name('analytics.api.revenue');
        Route::get('products', [AnalyticsApiController::class, 'products'])->name('analytics.api.products');
        Route::get('categories', [AnalyticsApiController::class, 'categories'])->name('analytics.api.categories');

        // Sessions & Engagement
        Route::get('sessions', [AnalyticsApiController::class, 'sessions'])->name('analytics.api.sessions');
        Route::get('page-visits', [AnalyticsApiController::class, 'pageVisits'])->name('analytics.api.page-visits');
        Route::get('funnel', [AnalyticsApiController::class, 'funnel'])->name('analytics.api.funnel');

        // Customer analytics
        Route::get('customers', [AnalyticsApiController::class, 'customers'])->name('analytics.api.customers');
        Route::get('cohorts', [AnalyticsApiController::class, 'cohorts'])->name('analytics.api.cohorts');

        // Campaign & attribution
        Route::get('campaigns', [AnalyticsApiController::class, 'campaigns'])->name('analytics.api.campaigns');
        Route::get('geographic', [AnalyticsApiController::class, 'geographic'])->name('analytics.api.geographic');

        // Export
        Route::get('export', [AnalyticsApiController::class, 'export'])->name('analytics.api.export');

        // Custom events
        Route::post('events/custom', [AnalyticsApiController::class, 'trackCustomEvent'])
            ->middleware('throttle:120,1')
            ->name('analytics.api.events.custom.track');
        Route::get('events/custom/definitions', [AnalyticsApiController::class, 'customEventDefinitions'])
            ->name('analytics.api.events.custom.definitions');
        Route::post('events/custom/definitions', [AnalyticsApiController::class, 'createCustomEventDefinition'])
            ->name('analytics.api.events.custom.definitions.create');

        // ─── Advanced Analytics (10 differentiating features) ─────────
        Route::prefix('advanced')->group(function () {
            // Predictive CLV
            Route::get('clv', [AdvancedAnalyticsController::class, 'clvPredict'])->name('analytics.advanced.clv');
            Route::post('clv/what-if', [AdvancedAnalyticsController::class, 'clvWhatIf'])->name('analytics.advanced.clv.what-if');

            // Revenue Waterfall
            Route::get('revenue-waterfall', [AdvancedAnalyticsController::class, 'revenueWaterfall'])->name('analytics.advanced.revenue-waterfall');

            // Why Explanation
            Route::post('why', [AdvancedAnalyticsController::class, 'whyExplain'])->name('analytics.advanced.why');

            // Behavioral Triggers
            Route::post('triggers/evaluate', [AdvancedAnalyticsController::class, 'behavioralTriggers'])->name('analytics.advanced.triggers.evaluate');

            // Customer Journey
            Route::get('journey', [AdvancedAnalyticsController::class, 'customerJourney'])->name('analytics.advanced.journey');
            Route::get('journey/drop-offs', [AdvancedAnalyticsController::class, 'dropOffPoints'])->name('analytics.advanced.journey.drop-offs');

            // Smart Recommendations
            Route::get('recommendations', [AdvancedAnalyticsController::class, 'recommendations'])->name('analytics.advanced.recommendations');

            // Audience Sync
            Route::get('audience/segments', [AdvancedAnalyticsController::class, 'audienceSegments'])->name('analytics.advanced.audience.segments');
            Route::post('audience/sync', [AdvancedAnalyticsController::class, 'audienceSync'])->name('analytics.advanced.audience.sync');
            Route::get('audience/destinations', [AdvancedAnalyticsController::class, 'audienceDestinations'])->name('analytics.advanced.audience.destinations');

            // Real-Time Alerts & Pulse
            Route::get('pulse', [AdvancedAnalyticsController::class, 'realtimePulse'])->name('analytics.advanced.pulse');
            Route::get('alerts', [AdvancedAnalyticsController::class, 'realtimeAlerts'])->name('analytics.advanced.alerts');
            Route::post('alerts/{alert}/acknowledge', [AdvancedAnalyticsController::class, 'acknowledgeAlert'])->name('analytics.advanced.alerts.acknowledge');

            // Natural Language Query
            Route::get('ask', [AdvancedAnalyticsController::class, 'nlQuery'])->name('analytics.advanced.ask');
            Route::get('ask/suggest', [AdvancedAnalyticsController::class, 'nlSuggest'])->name('analytics.advanced.ask.suggest');

            // Competitive Benchmarks
            Route::get('benchmarks', [AdvancedAnalyticsController::class, 'competitiveBenchmarks'])->name('analytics.advanced.benchmarks');
        });
    });
});
