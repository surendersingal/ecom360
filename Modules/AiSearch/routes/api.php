<?php

use Illuminate\Support\Facades\Route;
use Modules\AiSearch\Http\Controllers\AiSearchController;
use App\Http\Middleware\AuthenticateApiKeyOrSanctum;

/*
 *--------------------------------------------------------------------------
 * API Routes — AI Search Module
 *--------------------------------------------------------------------------
 *
 * Supports two authentication methods:
 *   • X-Ecom360-Key header  → Magento storefront widget (public)
 *   • Bearer token (Sanctum) → Admin dashboard
 *
*/

Route::prefix('v1/search')
    ->middleware([AuthenticateApiKeyOrSanctum::class, 'tenant.permission:ai_search.query', 'throttle:120,1'])
    ->group(function () {
        // Main search — widget sends GET /search/search, dashboard sends POST /search
        Route::match(['get', 'post'], '/', [AiSearchController::class, 'search']);
        Route::match(['get', 'post'], '/search', [AiSearchController::class, 'search']);

        Route::get('/suggest', [AiSearchController::class, 'suggest']);
        Route::get('/widget-config', [AiSearchController::class, 'widgetConfig']);
        Route::get('/trending', [AiSearchController::class, 'trending']);
        Route::get('/similar/{productId}', [AiSearchController::class, 'similar']);
        Route::get('/analytics', [AiSearchController::class, 'analytics']);

        Route::post('/visual', [AiSearchController::class, 'visualSearch']);
        Route::post('/visual-search', [AiSearchController::class, 'visualSearch']);
    });

// ─── CORS preflight for widget endpoints ─────────────────────────────
Route::options('v1/search/{any?}', function () {
    return response('', 204)
        ->header('Access-Control-Allow-Origin', request()->header('Origin', '*'))
        ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, X-Ecom360-Key, X-Requested-With')
        ->header('Access-Control-Allow-Credentials', 'true')
        ->header('Access-Control-Max-Age', '86400');
})->where('any', '.*');
