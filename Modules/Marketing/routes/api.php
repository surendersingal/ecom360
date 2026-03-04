<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Marketing\Http\Controllers\Api\ContactController;
use Modules\Marketing\Http\Controllers\Api\TemplateController;
use Modules\Marketing\Http\Controllers\Api\CampaignController;
use Modules\Marketing\Http\Controllers\Api\FlowController;
use Modules\Marketing\Http\Controllers\Api\ChannelController;
use Modules\Marketing\Http\Controllers\Api\WebhookController;

// ─── Public Webhook Routes (no auth — validated by provider signatures) ──
Route::prefix('v1/marketing/webhooks')->group(function () {
    Route::post('{provider}', [WebhookController::class, 'handle'])->name('marketing.webhooks');
});

// ─── Authenticated Routes ────────────────────────────────────────────────
Route::middleware(['auth:sanctum'])->prefix('v1/marketing')->group(function () {

    // Contacts
    Route::apiResource('contacts', ContactController::class)->names('marketing.contacts');
    Route::post('contacts/bulk-import', [ContactController::class, 'bulkImport'])->name('marketing.contacts.bulk-import');
    Route::post('contacts/{contact}/unsubscribe', [ContactController::class, 'unsubscribe'])->name('marketing.contacts.unsubscribe');

    // Contact Lists
    Route::get('lists', [ContactController::class, 'lists'])->name('marketing.lists.index');
    Route::post('lists', [ContactController::class, 'createList'])->name('marketing.lists.store');
    Route::post('lists/{list}/members', [ContactController::class, 'addToList'])->name('marketing.lists.add-members');
    Route::delete('lists/{list}/members', [ContactController::class, 'removeFromList'])->name('marketing.lists.remove-members');

    // Templates
    Route::apiResource('templates', TemplateController::class)->names('marketing.templates');
    Route::get('templates/{template}/preview', [TemplateController::class, 'preview'])->name('marketing.templates.preview');
    Route::post('templates/{template}/duplicate', [TemplateController::class, 'duplicate'])->name('marketing.templates.duplicate');

    // Campaigns
    Route::apiResource('campaigns', CampaignController::class)->names('marketing.campaigns');
    Route::post('campaigns/{campaign}/send', [CampaignController::class, 'send'])->name('marketing.campaigns.send');
    Route::get('campaigns/{campaign}/stats', [CampaignController::class, 'stats'])->name('marketing.campaigns.stats');
    Route::post('campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate'])->name('marketing.campaigns.duplicate');

    // Flows (automation)
    Route::apiResource('flows', FlowController::class)->names('marketing.flows');
    Route::put('flows/{flow}/canvas', [FlowController::class, 'saveCanvas'])->name('marketing.flows.canvas');
    Route::post('flows/{flow}/activate', [FlowController::class, 'activate'])->name('marketing.flows.activate');
    Route::post('flows/{flow}/pause', [FlowController::class, 'pause'])->name('marketing.flows.pause');
    Route::post('flows/{flow}/enroll', [FlowController::class, 'enroll'])->name('marketing.flows.enroll');
    Route::get('flows/{flow}/stats', [FlowController::class, 'stats'])->name('marketing.flows.stats');

    // Channels
    Route::apiResource('channels', ChannelController::class)->names('marketing.channels');
    Route::post('channels/{channel}/test', [ChannelController::class, 'test'])->name('marketing.channels.test');
    Route::get('channels/providers/{type}', [ChannelController::class, 'providers'])->name('marketing.channels.providers');
});
