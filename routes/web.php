<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\ChilledRunController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerSpecialPriceController;
use App\Http\Controllers\DeliveryRunController;
use App\Http\Controllers\Invoicing\ConsolidatedInvoiceController;
use App\Http\Controllers\Invoicing\CreditNoteController;
use App\Http\Controllers\Invoicing\DocumentEmailController;
use App\Http\Controllers\Invoicing\ExportController;
use App\Http\Controllers\Invoicing\InvoiceController;
use App\Http\Controllers\Invoicing\OverdueController;
use App\Http\Controllers\Invoicing\PaymentController;
use App\Http\Controllers\Invoicing\StatementBatchController;
use App\Http\Controllers\Invoicing\StatementController;
use App\Http\Controllers\Invoicing\UninvoicedOrderController;
use App\Http\Controllers\OnlineOrdersController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductVariantController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

// No public landing page: the app is a closed system, so `/` goes to the
// dashboard (or the login screen). The stock welcome view is gone.
Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

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

        // Finance queues — the three numbers that mean "act now".
        $data['invoicing'] = \App\Http\Controllers\Invoicing\InvoiceController::headlineStats();

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
    // Orders are cancelled (status), never deleted — there is no destroy().
    Route::resource('orders', \App\Http\Controllers\OrderController::class)->except(['destroy']);
    Route::get('/orders/{order}/docket', [\App\Http\Controllers\OrderController::class, 'docket'])->name('orders.docket');
    Route::post('/orders/{order}/items', [\App\Http\Controllers\OrderController::class, 'storeItem'])->name('orders.items.store');
    Route::patch('/orders/{order}/items/{orderItem}', [\App\Http\Controllers\OrderController::class, 'updateItem'])->name('orders.items.update');
    Route::delete('/orders/{order}/items/{orderItem}', [\App\Http\Controllers\OrderController::class, 'destroyItem'])->name('orders.items.destroy');
    // Short supply — close a line that can't be filled / reopen it. Office
    // ability (authorize('update')), so factory 403s in the controller.
    Route::post('/orders/{order}/items/{orderItem}/short', [\App\Http\Controllers\OrderController::class, 'shortCloseItem'])->name('orders.items.short');
    Route::post('/orders/{order}/items/{orderItem}/reopen', [\App\Http\Controllers\OrderController::class, 'reopenItem'])->name('orders.items.reopen');

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
    // Short supply from the floor — the picker holding the empty crate is the
    // person who knows. Same narrow fulfill ability as picking itself.
    Route::post('/picking/{order}/items/{orderItem}/short', [\App\Http\Controllers\PickingController::class, 'markShort'])->name('picking.short');
    Route::post('/picking/{order}/items/{orderItem}/reopen', [\App\Http\Controllers\PickingController::class, 'reopenShort'])->name('picking.reopen');

    // Chilled run sheet — read view of a run's stops + quantities. The one
    // write is the "loaded onto van" tick, gated by the narrow
    // OrderPolicy::load carve-out (factory can tick, can't edit orders).
    Route::get('/chilled-runs', [ChilledRunController::class, 'index'])->name('chilled-runs.index');
    Route::post('/chilled-runs/orders/{order}/loaded', [ChilledRunController::class, 'toggleLoaded'])->name('chilled-runs.toggle-loaded');
});

// Admin/office-only: factory denied entirely. These flows contain financial
// or process data the factory floor doesn't need.
Route::middleware(['auth', 'role:admin,office'])->group(function () {
    // Dispatch and delivery are office work now: dispatching issues the invoice
    // and emails the dispatch confirmation (App\Services\DispatchService).
    Route::post('/orders/{order}/dispatch', [\App\Http\Controllers\OrderController::class, 'markDispatched'])->name('orders.dispatch');
    Route::post('/orders/{order}/deliver', [\App\Http\Controllers\OrderController::class, 'markDelivered'])->name('orders.deliver');

    // The priced dispatch confirmation — view/print (HTML, ?download=1 for PDF)
    // and a manual resend for when the automatic send at dispatch failed or
    // the customer had no address at the time.
    Route::get('/orders/{order}/dispatch-confirmation', [\App\Http\Controllers\OrderController::class, 'dispatchConfirmation'])->name('orders.dispatch-confirmation');
    Route::post('/orders/{order}/dispatch-confirmation/email', [\App\Http\Controllers\OrderController::class, 'emailDispatchConfirmation'])->name('orders.dispatch-confirmation.email');

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
    // FIFO stock reservation from the sheet — run-wide and per stop.
    Route::post('/chilled-runs/allocate-all', [ChilledRunController::class, 'allocateAll'])->name('chilled-runs.allocate-all');
    Route::post('/chilled-runs/stops/{customer}/allocate', [ChilledRunController::class, 'allocateStop'])->name('chilled-runs.allocate-stop');
    // Dispatch every loaded, ready stop on the run — flips status, issues
    // per-order invoices and queues the dispatch confirmations.
    Route::post('/chilled-runs/dispatch-all', [ChilledRunController::class, 'dispatchAll'])->name('chilled-runs.dispatch-all');

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

    // Invoicing & statements — office/admin only. Factory is denied here at the
    // middleware, and FinancialPolicy denies it again at the model, because the
    // packing floor has no business seeing what a customer owes.
    Route::prefix('invoicing')->name('invoicing.')->group(function () {
        // Queues: what still needs billing, and what has been billed but not sent.
        Route::get('/', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/uninvoiced', [UninvoicedOrderController::class, 'index'])->name('uninvoiced.index');
        Route::post('/uninvoiced/issue-all', [UninvoicedOrderController::class, 'issueAll'])->name('uninvoiced.issue-all');
        Route::post('/orders/{order}/issue', [UninvoicedOrderController::class, 'issue'])->name('invoices.issue-order');

        // Consolidated run: pick a period, preview per customer, then issue.
        Route::get('/consolidated', [ConsolidatedInvoiceController::class, 'create'])->name('consolidated.create');
        Route::post('/consolidated', [ConsolidatedInvoiceController::class, 'store'])->name('consolidated.store');

        Route::get('/overdue', [OverdueController::class, 'index'])->name('overdue.index');
        Route::post('/overdue/remind-all', [OverdueController::class, 'remindAll'])->name('overdue.remind-all');

        Route::get('/emails', [DocumentEmailController::class, 'index'])->name('emails.index');
        Route::get('/export', [ExportController::class, 'index'])->name('export.index');
        Route::get('/export/download', [ExportController::class, 'download'])->name('export.download');

        // Payments are recorded against the CUSTOMER and allocated across
        // invoices, so they live beside invoices rather than under one.
        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/create', [PaymentController::class, 'create'])->name('payments.create');
        Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
        Route::delete('/payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');
        Route::post('/customers/{customer}/apply-credit', [PaymentController::class, 'applyCredit'])->name('payments.apply-credit');

        // Statements: one customer at a time, or the month-end batch.
        Route::get('/statements', [StatementController::class, 'index'])->name('statements.index');
        Route::get('/statements/batch', [StatementBatchController::class, 'create'])->name('statements.batch.create');
        Route::post('/statements/batch', [StatementBatchController::class, 'store'])->name('statements.batch.store');
        Route::get('/statements/batch/{batch}', [StatementBatchController::class, 'show'])->name('statements.batch.show');
        Route::post('/statements/batch/{batch}/retry', [StatementBatchController::class, 'retry'])->name('statements.batch.retry');
        Route::get('/customers/{customer}/statement', [StatementController::class, 'preview'])->name('statements.preview');
        Route::post('/customers/{customer}/statement', [StatementController::class, 'store'])->name('statements.store');
        Route::get('/statements/{statement}/pdf', [StatementController::class, 'pdf'])->name('statements.pdf');
        Route::post('/statements/{statement}/email', [StatementController::class, 'email'])->name('statements.email');

        // Credit notes hang off the invoice they credit.
        Route::get('/invoices/{invoice}/credit-notes/create', [CreditNoteController::class, 'create'])->name('credit-notes.create');
        Route::post('/invoices/{invoice}/credit-notes', [CreditNoteController::class, 'store'])->name('credit-notes.store');
        Route::get('/credit-notes/{credit_note}', [CreditNoteController::class, 'show'])->name('credit-notes.show');
        Route::get('/credit-notes/{credit_note}/pdf', [CreditNoteController::class, 'pdf'])->name('credit-notes.pdf');
        Route::post('/credit-notes/{credit_note}/email', [CreditNoteController::class, 'email'])->name('credit-notes.email');
        Route::post('/credit-notes/{credit_note}/void', [CreditNoteController::class, 'void'])->name('credit-notes.void');

        // Wildcard {invoice} routes come last so /uninvoiced, /overdue etc.
        // aren't swallowed by the model binding.
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
        Route::post('/invoices/{invoice}/email', [InvoiceController::class, 'email'])->name('invoices.email');
        Route::post('/invoices/{invoice}/remind', [InvoiceController::class, 'remind'])->name('invoices.remind');
        Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
    });
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
