<?php

use App\Http\Controllers\Api\ProductExportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Product export endpoint for external ordering system (mossorders)
// Protected by API token authentication, with rate limiting in front
// so unauthenticated probes also consume the budget.
Route::middleware(['throttle:sync-api', 'api.token'])->group(function () {
    Route::get('/products', [ProductExportController::class, 'index'])
        ->name('api.products.index');
});
