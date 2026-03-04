<?php

use Illuminate\Support\Facades\Route;
use Modules\Chatbot\Http\Controllers\ChatbotController;
use App\Http\Middleware\AuthenticateApiKeyOrSanctum;

/*
 *--------------------------------------------------------------------------
 * API Routes — Chatbot Module
 *--------------------------------------------------------------------------
 *
 * Supports two authentication methods:
 *   • X-Ecom360-Key header  → Magento storefront widget (public)
 *   • Bearer token (Sanctum) → Admin dashboard
 *
*/

Route::prefix('v1/chatbot')
    ->middleware([AuthenticateApiKeyOrSanctum::class, 'throttle:60,1'])
    ->group(function () {
        Route::post('/send', [ChatbotController::class, 'send']);
        Route::post('/rage-click', [ChatbotController::class, 'rageClick']);
        Route::get('/history/{conversationId}', [ChatbotController::class, 'history']);
        Route::get('/conversations', [ChatbotController::class, 'conversations']);
        Route::post('/resolve/{conversationId}', [ChatbotController::class, 'resolve']);
        Route::get('/widget-config', [ChatbotController::class, 'widgetConfig']);
        Route::get('/analytics', [ChatbotController::class, 'analytics']);
    });

// ─── CORS preflight for chatbot widget ───────────────────────────────
Route::options('v1/chatbot/{any?}', function () {
    return response('', 204)
        ->header('Access-Control-Allow-Origin', request()->header('Origin', '*'))
        ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, X-Ecom360-Key, X-Requested-With')
        ->header('Access-Control-Max-Age', '86400');
})->where('any', '.*');
