<?php

use App\Http\Controllers\BatchController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    $totalProducts = \App\Models\Product::count();
    $totalBatches = \App\Models\Batch::count();
    $activeBatches = \App\Models\Batch::where('status', 'active')->count();
    $cheeseReadySoon = \App\Models\Batch::whereHas('product', function($q) {
        $q->where('type', 'cheese');
    })->where('ready_date', '>', now())
      ->where('ready_date', '<=', now()->addDays(7))
      ->count();
    
    return view('dashboard', compact('totalProducts', 'totalBatches', 'activeBatches', 'cheeseReadySoon'));
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // Product management routes
    Route::resource('products', ProductController::class);
    
    // Batch management routes
    Route::resource('batches', BatchController::class);
    
    // Cheese cutting routes
    Route::get('/cheese-cutting', [\App\Http\Controllers\CheeseCuttingController::class, 'index'])->name('cheese-cutting.index');
    Route::get('/cheese-cutting/cut/{batchItem}', [\App\Http\Controllers\CheeseCuttingController::class, 'create'])->name('cheese-cutting.create');
    Route::post('/cheese-cutting/cut/{batchItem}', [\App\Http\Controllers\CheeseCuttingController::class, 'store'])->name('cheese-cutting.store');
    
    // Stock management routes
    Route::get('/stock', [\App\Http\Controllers\StockController::class, 'index'])->name('stock.index');
});

require __DIR__.'/auth.php';
