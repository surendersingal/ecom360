<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DataSync\Http\Controllers\SyncController;
use Modules\DataSync\Http\Middleware\ValidateSyncAuth;

/*
|--------------------------------------------------------------------------
| DataSync Module API Routes
|--------------------------------------------------------------------------
|
| Prefix: /api/v1/sync/*
|
| Authentication:
|   - Server-to-server sync endpoints use ValidateSyncAuth middleware
|     which validates both X-Ecom360-Key and X-Ecom360-Secret headers.
|   - Status endpoint can use either Sanctum or sync auth.
|
| These endpoints match the URLs called by:
|   - Magento 2 module (Ecom360_Analytics): DataSync.php, all Cron jobs
|   - WordPress plugin (ecom360-analytics): future bulk sync support
|
*/

// CORS preflight for cross-origin module requests.
Route::options('v1/sync/{any}', fn () => response('', 204)
    ->header('Access-Control-Allow-Origin', '*')
    ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
    ->header('Access-Control-Allow-Headers', 'Content-Type, X-Ecom360-Key, X-Ecom360-Secret')
    ->header('Access-Control-Max-Age', '86400')
)->where('any', '.*');

Route::middleware(ValidateSyncAuth::class)->prefix('v1/sync')->group(function () {

    /*
    |----------------------------------------------------------------------
    | Connection Management
    |----------------------------------------------------------------------
    */
    Route::post('/register',    [SyncController::class, 'register'])->name('datasync.register');
    Route::post('/heartbeat',   [SyncController::class, 'heartbeat'])->name('datasync.heartbeat');
    Route::post('/permissions', [SyncController::class, 'updatePermissions'])->name('datasync.permissions');

    /*
    |----------------------------------------------------------------------
    | Catalog Sync (Public — no consent needed)
    |----------------------------------------------------------------------
    */
    Route::post('/products',   [SyncController::class, 'syncProducts'])->name('datasync.products');
    Route::post('/categories', [SyncController::class, 'syncCategories'])->name('datasync.categories');
    Route::post('/inventory',  [SyncController::class, 'syncInventory'])->name('datasync.inventory');
    Route::post('/sales',      [SyncController::class, 'syncSales'])->name('datasync.sales');

    /*
    |----------------------------------------------------------------------
    | Customer Data Sync (Restricted/Sensitive — consent required)
    |----------------------------------------------------------------------
    */
    Route::post('/orders',          [SyncController::class, 'syncOrders'])->name('datasync.orders');
    Route::post('/customers',       [SyncController::class, 'syncCustomers'])->name('datasync.customers');
    Route::post('/abandoned-carts', [SyncController::class, 'syncAbandonedCarts'])->name('datasync.abandoned-carts');
    Route::post('/popup-captures',  [SyncController::class, 'syncPopupCaptures'])->name('datasync.popup-captures');

    /*
    |----------------------------------------------------------------------
    | Status
    |----------------------------------------------------------------------
    */
    Route::get('/status', [SyncController::class, 'status'])->name('datasync.status');
});
