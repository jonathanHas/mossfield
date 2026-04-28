<?php

use App\Http\Controllers\BatchController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\OnlineOrdersController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductVariantController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    $user = auth()->user();

    // Only fetch stats the current role is expected to see. Office/admin get
    // everything; factory gets production-floor metrics; driver gets a
    // placeholder until their route manifest screens land.
    $data = ['user' => $user];

    if ($user->hasRole('admin', 'office', 'factory')) {
        $data['totalProducts'] = \App\Models\Product::count();
        $data['activeProducts'] = \App\Models\Product::where('is_active', true)->count();

        $data['maturingCount'] = \App\Models\Batch::where('status', 'active')
            ->where('ready_date', '>', now())
            ->count();
        $data['readyNowCount'] = \App\Models\Batch::where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('ready_date')->orWhere('ready_date', '<=', now());
            })
            ->count();

        // Side-rail: next few batches closest to maturation.
        $data['maturingSoon'] = \App\Models\Batch::with('product')
            ->where('status', 'active')
            ->whereNotNull('ready_date')
            ->where('ready_date', '>', now())
            ->orderBy('ready_date', 'asc')
            ->take(3)
            ->get();

        // Ready-to-ship orders (relevant to factory too for awareness).
        $data['readyToShipCount'] = \App\Models\Order::where('status', 'ready')->count();
    }

    if ($user->hasRole('admin', 'office')) {
        // Order queue: confirmed/preparing orders still needing allocation.
        // Mirrors OrderAllocationController@index's "partially/unallocated" filter.
        $unallocatedQuery = \App\Models\Order::whereIn('status', ['pending', 'confirmed', 'preparing'])
            ->whereHas('orderItems', function ($q) {
                $q->whereRaw('quantity_allocated < quantity_ordered');
            });

        $data['unallocatedCount'] = (clone $unallocatedQuery)->count();
        $data['unallocatedOrders'] = $unallocatedQuery
            ->with(['customer', 'orderItems.productVariant.product'])
            ->orderBy('order_date', 'asc')
            ->take(5)
            ->get();

        $data['totalCustomers'] = \App\Models\Customer::count();
        $data['activeCustomers'] = \App\Models\Customer::where('is_active', true)->count();
        $data['lastOnlineOrderImportAt'] = Cache::get('sync:import_orders:last_ok');
    }

    return view('dashboard', $data);
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Shared business routes — admin/office full access; factory view-only
// (writes blocked by authorizeResource + BasePolicy in each controller).
Route::middleware(['auth', 'role:admin,office,factory'])->group(function () {
    Route::resource('products', ProductController::class);
    Route::resource('batches', BatchController::class);
    Route::resource('orders', \App\Http\Controllers\OrderController::class);

    Route::get('/cheese-cutting', [\App\Http\Controllers\CheeseCuttingController::class, 'index'])->name('cheese-cutting.index');
    Route::get('/stock', [\App\Http\Controllers\StockController::class, 'index'])->name('stock.index');
});

// Admin/office-only: factory denied entirely. These flows contain financial
// or process data the factory floor doesn't need.
Route::middleware(['auth', 'role:admin,office'])->group(function () {
    Route::resource('customers', CustomerController::class);
    Route::resource('products.variants', ProductVariantController::class)
        ->except(['index', 'show']);

    // Cheese cutting write actions — factory will get this later.
    Route::get('/cheese-cutting/cut/{batchItem}', [\App\Http\Controllers\CheeseCuttingController::class, 'create'])->name('cheese-cutting.create');
    Route::post('/cheese-cutting/cut/{batchItem}', [\App\Http\Controllers\CheeseCuttingController::class, 'store'])->name('cheese-cutting.store');

    // Order allocation
    Route::get('/order-allocations', [\App\Http\Controllers\OrderAllocationController::class, 'index'])->name('order-allocations.index');
    Route::get('/order-allocations/{order}', [\App\Http\Controllers\OrderAllocationController::class, 'show'])->name('order-allocations.show');
    Route::post('/order-allocations/{orderItem}/allocate', [\App\Http\Controllers\OrderAllocationController::class, 'allocate'])->name('order-allocations.allocate');
    Route::delete('/order-allocations/{allocation}', [\App\Http\Controllers\OrderAllocationController::class, 'deallocate'])->name('order-allocations.deallocate');
    Route::post('/order-allocations/{allocation}/fulfill', [\App\Http\Controllers\OrderAllocationController::class, 'fulfill'])->name('order-allocations.fulfill');
    Route::post('/order-allocations/{allocation}/unfulfill', [\App\Http\Controllers\OrderAllocationController::class, 'unfulfill'])->name('order-allocations.unfulfill');
    Route::post('/order-allocations/{order}/auto-allocate', [\App\Http\Controllers\OrderAllocationController::class, 'autoAllocate'])->name('order-allocations.auto-allocate');

    // Online orders (Mossorders integration)
    Route::get('/online-orders', [OnlineOrdersController::class, 'index'])->name('online-orders.index');
    Route::get('/online-orders/preview', [OnlineOrdersController::class, 'preview'])->name('online-orders.preview');
    Route::post('/online-orders/import', [OnlineOrdersController::class, 'import'])->name('online-orders.import');
});

// User management — admin only.
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::resource('users', UserController::class)->except(['show']);
    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
});

require __DIR__.'/auth.php';
