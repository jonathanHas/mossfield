<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\ChilledRunController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerSpecialPriceController;
use App\Http\Controllers\DeliveryRunController;
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
    Route::get('/orders/{order}/docket', [\App\Http\Controllers\OrderController::class, 'docket'])->name('orders.docket');
    Route::post('/orders/{order}/dispatch', [\App\Http\Controllers\OrderController::class, 'markDispatched'])->name('orders.dispatch');
    Route::post('/orders/{order}/deliver', [\App\Http\Controllers\OrderController::class, 'markDelivered'])->name('orders.deliver');
    Route::post('/orders/{order}/items', [\App\Http\Controllers\OrderController::class, 'storeItem'])->name('orders.items.store');
    Route::patch('/orders/{order}/items/{orderItem}', [\App\Http\Controllers\OrderController::class, 'updateItem'])->name('orders.items.update');
    Route::delete('/orders/{order}/items/{orderItem}', [\App\Http\Controllers\OrderController::class, 'destroyItem'])->name('orders.items.destroy');

    Route::get('/cheese-cutting', [\App\Http\Controllers\CheeseCuttingController::class, 'index'])->name('cheese-cutting.index');
    Route::get('/cheese-conversion', [\App\Http\Controllers\CheeseConversionController::class, 'index'])->name('cheese-conversion.index');
    Route::get('/stock', [\App\Http\Controllers\StockController::class, 'index'])->name('stock.index');

    // Mobile picking flow — the factory write carve-out. Every action checks
    // the narrow OrderPolicy::fulfill ability (allocate + fulfil + undo only);
    // general order editing stays office/admin.
    Route::get('/picking', [\App\Http\Controllers\PickingController::class, 'index'])->name('picking.index');
    Route::get('/picking/{order}', [\App\Http\Controllers\PickingController::class, 'show'])->name('picking.show');
    Route::get('/picking/{order}/items/{orderItem}', [\App\Http\Controllers\PickingController::class, 'item'])->name('picking.item');
    Route::post('/picking/{order}/items/{orderItem}/pick', [\App\Http\Controllers\PickingController::class, 'pick'])->name('picking.pick');
    Route::post('/picking/{order}/items/{orderItem}/undo', [\App\Http\Controllers\PickingController::class, 'undo'])->name('picking.undo');

    // Chilled run sheet — read view of a run's stops + quantities. The one
    // write is the "loaded onto van" tick, gated by the narrow
    // OrderPolicy::load carve-out (factory can tick, can't edit orders).
    Route::get('/chilled-runs', [ChilledRunController::class, 'index'])->name('chilled-runs.index');
    Route::post('/chilled-runs/orders/{order}/loaded', [ChilledRunController::class, 'toggleLoaded'])->name('chilled-runs.toggle-loaded');
});

// Admin/office-only: factory denied entirely. These flows contain financial
// or process data the factory floor doesn't need.
Route::middleware(['auth', 'role:admin,office'])->group(function () {
    // Order invoice — office/admin only (carries prices, unlike the dispatch docket).
    // HTML view by default; ?download=1 returns the PDF.
    Route::get('/orders/{order}/invoice', [\App\Http\Controllers\OrderController::class, 'invoice'])->name('orders.invoice');

    // Email a document (invoice or docket) to the customer as a PDF attachment.
    // Office/admin only — emailing a customer is office work (factory can still
    // view/print the docket, just not email it). Invoice adds see-financials + ready guards.
    Route::post('/orders/{order}/email/{document}', [\App\Http\Controllers\OrderController::class, 'emailDocument'])
        ->where('document', 'invoice|docket')
        ->name('orders.email');

    Route::resource('customers', CustomerController::class);
    // Per-customer alternative prices (managed from the customer show page).
    Route::resource('customers.special-prices', CustomerSpecialPriceController::class)
        ->only(['store', 'update', 'destroy']);
    Route::resource('products.variants', ProductVariantController::class)
        ->except(['index', 'show']);

    // Cheese cutting write actions — factory will get this later.
    Route::get('/cheese-cutting/cut/{batchItem}', [\App\Http\Controllers\CheeseCuttingController::class, 'create'])->name('cheese-cutting.create');
    Route::post('/cheese-cutting/cut/{batchItem}', [\App\Http\Controllers\CheeseCuttingController::class, 'store'])->name('cheese-cutting.store');

    // Mature conversion (factory views the index, can't write). "hold" sets the
    // reversible maturing reservation (any age); "release" turns aged held wheels
    // into the Mature product; "undo" returns released wheels to the hold.
    Route::post('/cheese-conversion/hold/{batchItem}', [\App\Http\Controllers\CheeseConversionController::class, 'hold'])->name('cheese-conversion.hold');
    Route::post('/cheese-conversion/release/{batchItem}', [\App\Http\Controllers\CheeseConversionController::class, 'release'])->name('cheese-conversion.release');
    Route::post('/cheese-conversion/logs/{log}/undo', [\App\Http\Controllers\CheeseConversionController::class, 'undoRelease'])->name('cheese-conversion.undo');

    // Order allocation
    Route::get('/order-allocations', [\App\Http\Controllers\OrderAllocationController::class, 'index'])->name('order-allocations.index');
    Route::get('/order-allocations/{order}', [\App\Http\Controllers\OrderAllocationController::class, 'show'])->name('order-allocations.show');
    Route::post('/order-allocations/{orderItem}/allocate', [\App\Http\Controllers\OrderAllocationController::class, 'allocate'])->name('order-allocations.allocate');
    Route::delete('/order-allocations/{allocation}', [\App\Http\Controllers\OrderAllocationController::class, 'deallocate'])->name('order-allocations.deallocate');
    Route::post('/order-allocations/{allocation}/fulfill', [\App\Http\Controllers\OrderAllocationController::class, 'fulfill'])->name('order-allocations.fulfill');
    Route::post('/order-allocations/{allocation}/unfulfill', [\App\Http\Controllers\OrderAllocationController::class, 'unfulfill'])->name('order-allocations.unfulfill');
    Route::post('/order-allocations/{order}/auto-allocate', [\App\Http\Controllers\OrderAllocationController::class, 'autoAllocate'])->name('order-allocations.auto-allocate');

    // Chilled run sheet order entry — per-stop save from the row editor.
    // Office/admin only (factory keeps the read view + loaded tick).
    Route::post('/chilled-runs/stops/{customer}/order', [ChilledRunController::class, 'saveStop'])->name('chilled-runs.save-stop');
    Route::post('/chilled-runs/confirm-all', [ChilledRunController::class, 'confirmAll'])->name('chilled-runs.confirm-all');

    // Delivery run management — define runs and assign customers/stops.
    Route::resource('delivery-runs', DeliveryRunController::class)->except(['show']);
    Route::post('/delivery-runs/{deliveryRun}/assign', [DeliveryRunController::class, 'assign'])->name('delivery-runs.assign');
    Route::post('/delivery-runs/{deliveryRun}/reorder', [DeliveryRunController::class, 'reorder'])->name('delivery-runs.reorder');
    Route::post('/customers/{customer}/unassign-run', [DeliveryRunController::class, 'unassign'])->name('delivery-runs.unassign');
    Route::post('/delivery-runs/stops/{customer}/charge', [DeliveryRunController::class, 'toggleCharge'])->name('delivery-runs.toggle-charge');

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

    // Backup & Restore.
    Route::get('/backup', [BackupController::class, 'index'])->name('backup.index');
    Route::post('/backup/download', [BackupController::class, 'download'])->name('backup.download');
    Route::post('/backup/restore', [BackupController::class, 'restore'])->name('backup.restore');
});

require __DIR__.'/auth.php';
