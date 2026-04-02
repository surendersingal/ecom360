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

        // Advanced Chat (UC36-40)
        Route::post('/advanced/order-tracking', [ChatbotController::class, 'advancedOrderTracking']);
        Route::post('/advanced/objection-handler', [ChatbotController::class, 'objectionHandler']);
        Route::post('/advanced/subscription', [ChatbotController::class, 'subscriptionManagement']);
        Route::post('/advanced/gift-card', [ChatbotController::class, 'giftCardBuilder']);
        Route::post('/advanced/video-review', [ChatbotController::class, 'videoReviewGuide']);

        // Proactive Support (UC31-35)
        Route::post('/proactive/order-modification', [ChatbotController::class, 'orderModification']);
        Route::post('/proactive/sentiment-escalation', [ChatbotController::class, 'sentimentEscalation']);
        Route::post('/proactive/vip-greeting', [ChatbotController::class, 'vipGreeting']);
        Route::post('/proactive/warranty-claim', [ChatbotController::class, 'warrantyClaim']);
        Route::post('/proactive/sizing-assistant', [ChatbotController::class, 'sizingAssistant']);

        // Form Actions & Communication
        Route::post('/form-submit', [ChatbotController::class, 'formSubmit']);
        Route::post('/communicate', [ChatbotController::class, 'communicate']);
        Route::post('/communicate-multi', [ChatbotController::class, 'communicateMulti']);
        Route::get('/communications', [ChatbotController::class, 'communicationHistory']);
    });

// ─── CORS preflight for chatbot widget ───────────────────────────────
Route::options('v1/chatbot/{any?}', function () {
    return response('', 204)
        ->header('Access-Control-Allow-Origin', request()->header('Origin', '*'))
        ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, X-Ecom360-Key, X-Requested-With')
        ->header('Access-Control-Allow-Credentials', 'true')
        ->header('Access-Control-Max-Age', '86400');
})->where('any', '.*');
