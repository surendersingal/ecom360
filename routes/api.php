<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| Dashboard Widget Engine
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/dashboard/widgets', [DashboardController::class, 'getWidgets']);
});
