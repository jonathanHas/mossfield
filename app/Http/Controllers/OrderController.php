<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsAllocationData;
use App\Http\Controllers\Concerns\MutatesOrderLines;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OrderController extends Controller
{
    use BuildsAllocationData;
    use MutatesOrderLines;

    /**
     * Block a mutation that would silently disagree with an issued invoice.
     *
     * The invoice is a snapshot, so editing lines or charges after issue doesn't
     * corrupt it — but it does leave the order and the customer's bill telling
     * different stories. Void the invoice to reopen the order instead.
     */
    private function blockedByInvoice(Order $order, string $what): ?RedirectResponse
    {
        if (! $order->isInvoiced()) {
            return null;
        }

        return redirect()->route('orders.show', $order)->with(
            'error',
            "This order is on invoice {$order->invoice->number}, so {$what} would no longer match the bill. Void the invoice first."
        );
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Order::class);
        $query = Order::with(['customer', 'orderItems.productVariant']);

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $orders = $query->orderBy('order_date', 'desc')->paginate(20);
        $customers = Customer::where('is_active', true)->orderBy('name')->get();

        return view('orders.index', compact('orders', 'customers'));
    }

    public function show(Request $request, Order $order): View
    {
        $this->authorize('view', $order);
        $order->load([
            'customer',
            'orderItems.productVariant.product',
            'orderItems.orderAllocations.batchItem.batch',
        ]);

        $listFilters = array_filter($request->only(['status', 'payment_status', 'customer_id']), fn ($v) => $v !== null && $v !== '');

        $listLimit = 50;
        $listQuery = Order::with(['customer', 'orderItems.productVariant.product'])
            ->orderBy('order_date', 'desc');

        foreach ($listFilters as $field => $value) {
            $listQuery->where($field, $value);
        }

        $listTotal = (clone $listQuery)->count();
        $orderList = $listQuery->limit($listLimit)->get();

        // Ensure the selected order is present in the list even when it falls outside the cap or filters.
        if (! $orderList->contains('id', $order->id)) {
            $orderList->prepend($order);
        }

        // The picking workflow (allocate/fulfill) is rendered inline on this page for
        // orders that are mid-fulfillment. Only build the batch-availability data when
        // the rich allocation block will actually render and the user can mutate it.
        $availableBatchItems = [];
        if (in_array($order->status, ['confirmed', 'preparing', 'ready'], true)
            && $request->user()->can('update', $order)) {
            $availableBatchItems = $this->buildAvailableBatchItems($order);
        }

        // Items can be added inline while the order is still open. Provide the
        // variant list for the picker only when that control will render.
        $productVariants = collect();
        if (! in_array($order->status, ['dispatched', 'delivered', 'cancelled'], true)
            && $request->user()->can('update', $order)) {
            $productVariants = $this->activeVariantsGrouped();
        }

        $dispatchConfirmation = $order->dispatched_at ? $order->latestDispatchConfirmation() : null;

        return view('orders.show', compact('order', 'orderList', 'listFilters', 'listTotal', 'listLimit', 'availableBatchItems', 'productVariants', 'dispatchConfirmation'));
    }

    /**
     * Render the dispatch docket (packing slip) for the order. Opens as an HTML page
     * by default (new window, with Print + Download buttons) so it always displays
     * rather than being downloaded; `?download=1` returns the PDF as a file download.
     * No prices — carries quantities and picked weights only, so factory can view/print it.
     */
    public function docket(Request $request, Order $order): \Symfony\Component\HttpFoundation\Response|View
    {
        $this->authorize('view', $order);

        $order->load([
            'customer',
            'orderItems.productVariant.product',
            'orderItems.orderAllocations.batchItem.batch',
        ]);

        if ($request->boolean('download')) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('orders.docket', ['order' => $order, 'pdf' => true]);

            return $pdf->download('docket-'.$order->order_number.'.pdf');
        }

        return view('orders.docket', ['order' => $order, 'pdf' => false]);
    }

    /**
     * View/print the priced dispatch confirmation — what went on the van and
     * its value. Office/admin only (carries prices). HTML by default;
     * ?download=1 returns the PDF. Gated on hasReachedReady() rather than
     * dispatched so it can be previewed once the order is picked.
     */
    public function dispatchConfirmation(Request $request, Order $order): \Symfony\Component\HttpFoundation\Response|View
    {
        $this->authorize('view', $order);
        abort_unless($request->user()->can('see-financials'), 403);

        if (! $order->hasReachedReady()) {
            return redirect()->route('orders.show', $order)
                ->with('error', 'A dispatch confirmation is only available once the order has reached ready status.');
        }

        $order->load([
            'customer',
            'orderItems.productVariant.product',
            'orderItems.orderAllocations.batchItem.batch',
        ]);

        if ($request->boolean('download')) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('orders.dispatch-confirmation', ['order' => $order, 'pdf' => true]);

            return $pdf->download('dispatch-confirmation-'.$order->order_number.'.pdf');
        }

        return view('orders.dispatch-confirmation', ['order' => $order, 'pdf' => false]);
    }

    /**
     * Manually (re)send the dispatch confirmation — for when the automatic send
     * at dispatch failed, or the customer had no address on file at the time.
     * Logged like every other send, so the order page can show it went.
     */
    public function emailDispatchConfirmation(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', $order);
        abort_unless($request->user()->can('see-financials'), 403);

        if (! $order->hasReachedReady()) {
            return back()->with('error', 'A dispatch confirmation is only available once the order has reached ready status.');
        }

        try {
            $row = app(\App\Services\DocumentMailer::class)->sendNow(
                new \App\Documents\DispatchConfirmation($order),
                $request->user(),
            );
        } catch (\App\Services\InvoicingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $row->status->value === 'sent'
            ? back()->with('success', 'Dispatch confirmation emailed to '.$row->recipient.'.')
            : back()->with('error', 'Could not send the email. Please try again or check the mail settings.');
    }

    public function create(): View
    {
        $this->authorize('create', Order::class);
        $customers = Customer::where('is_active', true)
            ->with(['specialPrices.productVariant', 'deliveryRun'])
            ->orderBy('name')
            ->get();
        $productVariants = $this->activeVariantsGrouped();

        // E2E F-20: the form previewed standard prices and no delivery, so the
        // total it showed wasn't what got saved. Give the script what store()
        // will use: special prices (as a per-unit estimate, like data-price)
        // and the customer's delivery charge or percentage.
        $customerPrices = $customers->mapWithKeys(fn (Customer $customer) => [
            $customer->id => $customer->specialPrices
                ->filter(fn ($special) => $special->productVariant?->is_active)
                ->mapWithKeys(fn ($special) => [
                    $special->product_variant_id => round(
                        (float) $special->price * ($special->productVariant->is_priced_by_weight
                            ? (float) ($special->productVariant->weight_kg ?? 0)
                            : 1),
                        2
                    ),
                ]),
        ])->filter(fn ($prices) => $prices->isNotEmpty());

        $customerCharges = $customers->mapWithKeys(fn (Customer $customer) => [
            $customer->id => [
                'charge' => $customer->currentDeliveryCharge(),
                'percent' => $customer->deliveryChargePercent(),
            ],
        ]);

        // Option labels follow the customer too: the special price in the
        // same units as price_label (€/unit or €/kg).
        $customerPriceLabels = $customers->mapWithKeys(fn (Customer $customer) => [
            $customer->id => $customer->specialPrices
                ->filter(fn ($special) => $special->productVariant?->is_active)
                ->mapWithKeys(fn ($special) => [
                    $special->product_variant_id => '€'.number_format((float) $special->price, 2)
                        .($special->productVariant->is_priced_by_weight ? '/kg' : ''),
                ]),
        ])->filter(fn ($labels) => $labels->isNotEmpty());

        // Delivery date default: the customer's next run date (this week's if
        // it hasn't passed, else next week's; whole-week runs → week start).
        $customerRunDates = $customers
            ->filter(fn (Customer $customer) => $customer->deliveryRun !== null)
            ->mapWithKeys(function (Customer $customer) {
                $date = $customer->deliveryRun->dateFor(now());
                if ($date->lt(today())) {
                    $date = $customer->deliveryRun->dateFor(now()->addWeek());
                }

                return [$customer->id => $date->toDateString()];
            });

        // Drops already carrying the run's charge in the next 14 days (one
        // query): [customer_id => [Y-m-d => order_number]], so the preview
        // shows €0 delivery for a second order on the same day (once per drop).
        $customerChargedDates = Order::query()
            ->whereIn('customer_id', $customers->pluck('id'))
            ->whereBetween('delivery_date', [today()->toDateString(), today()->addDays(14)->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->where('delivery_charge', '>', 0)
            ->orderBy('id')
            ->get(['customer_id', 'delivery_date', 'order_number'])
            ->groupBy('customer_id')
            ->map(fn ($orders) => $orders->unique(fn ($o) => $o->delivery_date->toDateString())
                ->mapWithKeys(fn ($o) => [$o->delivery_date->toDateString() => $o->order_number]));

        return view('orders.create', compact(
            'customers', 'productVariants', 'customerPrices', 'customerCharges', 'customerPriceLabels', 'customerRunDates', 'customerChargedDates'
        ));
    }

    /**
     * Active variants of active products, grouped by product name — the option
     * source for the order item picker on both create and show (add-item).
     */
    private function activeVariantsGrouped(): \Illuminate\Support\Collection
    {
        return ProductVariant::with('product')
            ->whereHas('product', function ($q) {
                $q->where('is_active', true);
            })
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->groupBy('product.name');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $items = collect($request->input('items', []))
            ->filter(fn ($item) => is_array($item) && ! empty($item['product_variant_id'] ?? null))
            ->values()
            ->all();
        $request->merge(['items' => $items]);

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'order_date' => 'required|date',
            'delivery_date' => 'nullable|date|after_or_equal:order_date',
            'delivery_address' => 'nullable|string',
            'notes' => 'nullable|string',
            'customer_reference' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
        ], [
            'items.required' => 'You must add at least one item to the order.',
            'items.min' => 'You must add at least one item to the order.',
        ], [
            // Human labels — "items.0.quantity" is not something an operator reads.
            'items.*.product_variant_id' => 'product',
            'items.*.quantity' => 'quantity',
        ]);

        // The same variant twice becomes one line with the summed quantity —
        // the rule storeItem() already applies (F-23).
        $validated['items'] = collect($validated['items'])
            ->groupBy(fn ($item) => (int) $item['product_variant_id'])
            ->map(fn ($rows, $variantId) => [
                'product_variant_id' => $variantId,
                'quantity' => (int) $rows->sum(fn ($row) => (int) $row['quantity']),
            ])
            ->values()
            ->all();

        $chargeCarrier = null;
        $order = DB::transaction(function () use ($validated, &$chargeCarrier) {
            // Price lines at the customer's rate and snapshot their delivery
            // charge (a per-variant special price if set, else base_price).
            $customer = Customer::find($validated['customer_id']);

            // Once per drop: a second order for a day that already carries the
            // run's charge gets none (no date → no drop to compare).
            $deliveryDate = isset($validated['delivery_date']) ? Carbon::parse($validated['delivery_date']) : null;
            $chargeCarrier = $deliveryDate && $customer->currentDeliveryCharge() > 0
                ? $customer->deliveryChargeCarrierFor($deliveryDate)
                : null;

            // Create the order
            $order = Order::create([
                'customer_id' => $validated['customer_id'],
                'order_date' => $validated['order_date'],
                // Optional keys are absent (not null) when a client omits them —
                // validate() drops what was never submitted (finding 11).
                'delivery_date' => $validated['delivery_date'] ?? null,
                'delivery_charge' => $customer->currentDeliveryCharge($deliveryDate),
                'delivery_charge_percent' => $customer->deliveryChargePercent(),
                'delivery_address' => $validated['delivery_address'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'customer_reference' => $validated['customer_reference'] ?? null,
                'status' => 'pending',
                'payment_status' => 'pending',
            ]);

            foreach ($validated['items'] as $itemData) {
                $variant = ProductVariant::find($itemData['product_variant_id']);

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $itemData['product_variant_id'],
                    'quantity_ordered' => $itemData['quantity'],
                    'unit_price' => $customer->unitPriceFor($variant),
                ]);
            }

            // Calculate totals
            $order->calculateTotals();

            return $order;
        });

        $message = 'Order '.$order->order_number.' created successfully!';
        if ($chargeCarrier) {
            $message .= " Delivery charge already on {$chargeCarrier->order_number} for that day.";
        }

        $redirect = redirect()->route('orders.show', $order)->with('success', $message);

        // Over the credit limit: a warning alongside, never a refusal.
        if ($warning = $order->customer->creditWarning((float) $order->total_amount)) {
            $redirect->with('warning', $warning);
        }

        return $redirect;
    }

    public function edit(Order $order): View
    {
        $this->authorize('update', $order);
        $order->load(['orderItems.productVariant']);
        $customers = Customer::where('is_active', true)->orderBy('name')->get();

        return view('orders.edit', compact('order', 'customers'));
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        // Shipping still works: markDispatched()/markDelivered() are their own
        // routes. What's blocked here is editing charges or cancelling an order
        // the customer has already been billed for.
        if ($blocked = $this->blockedByInvoice($order, 'editing or cancelling it')) {
            return $blocked;
        }

        $validated = $request->validate([
            'delivery_date' => 'nullable|date|after_or_equal:order_date',
            // Absent from the edit form; posted only by the show page's
            // Confirm / Cancel quick-actions (the $statusFields replay).
            'status' => 'sometimes|in:pending,confirmed,preparing,ready,dispatched,delivered,cancelled',
            'delivery_charge' => 'nullable|numeric|min:0|max:999999.99',
            'delivery_charge_percent' => 'nullable|numeric|min:0|max:100',
            'delivery_address' => 'nullable|string',
            'notes' => 'nullable|string',
            'customer_reference' => 'nullable|string|max:255',
        ]);

        // Blank/absent input zeroes the fixed charge (the column is NOT NULL).
        $validated['delivery_charge'] = round((float) ($validated['delivery_charge'] ?? 0), 2);

        // A percentage (when > 0) overrides the € amount — calculateTotals()
        // recomputes delivery_charge from it. Blank clears it back to fixed.
        $percent = $validated['delivery_charge_percent'] ?? null;
        $validated['delivery_charge_percent'] = ($percent !== null && (float) $percent > 0) ? round((float) $percent, 2) : null;

        $validated['status'] ??= $order->status;

        // E2E F-19: a free status field let an order jump to "dispatched" with
        // no invoice, no dispatch time and nothing picked. Only these moves are
        // made here; everything else has its own route (picking/allocation,
        // orders.dispatch → DispatchService, orders.deliver).
        if (! $this->isAllowedStatusChange($order, $validated['status'])) {
            return redirect()->route('orders.show', $order)
                ->with('error', 'Order status moves through the workflow — use Confirm, picking, Mark dispatched or Mark delivered.');
        }

        $isCancelling = $validated['status'] === 'cancelled' && $order->status !== 'cancelled';
        $isConfirming = $order->status === 'pending' && $validated['status'] === 'confirmed';
        $releasedUnits = 0;

        DB::transaction(function () use ($order, $validated, $isCancelling, &$releasedUnits) {
            $order->update($validated);

            // Re-derive tax/total from the (possibly edited) delivery charge.
            $order->calculateTotals();

            if (! $isCancelling) {
                return;
            }

            // Cancelling returns every reserved and picked unit to its batch —
            // reserved units just drop their reservation, picked units restore
            // BatchItem.quantity_remaining (via OrderItem::releaseUnits).
            $order->load('orderItems');

            foreach ($order->orderItems as $orderItem) {
                $releasedUnits += $orderItem->releaseUnits($orderItem->quantity_allocated);
            }
        });

        $message = $isConfirming ? 'Order confirmed.' : 'Order updated successfully.';
        if ($isCancelling) {
            $message = $releasedUnits > 0
                ? 'Order cancelled. Reserved and picked stock has been returned to its batch.'
                : 'Order cancelled.';
        }

        $redirect = redirect()->route('orders.show', $order)->with('success', $message);

        // Confirming an order that takes the customer over their credit limit
        // warns alongside — never a refusal (OPEN_QUESTIONS #5).
        if ($isConfirming && ($warning = $order->customer->creditWarning((float) $order->fresh()->total_amount))) {
            $redirect->with('warning', $warning);
        }

        return $redirect;
    }

    /** Unchanged, pending → confirmed, or → cancelled while the order can be. */
    private function isAllowedStatusChange(Order $order, string $to): bool
    {
        return $to === $order->status
            || ($order->status === 'pending' && $to === 'confirmed')
            || ($to === 'cancelled' && $order->canBeCancelled());
    }

    /**
     * Dispatch one order from its page. Goes through DispatchService so the
     * side effects (invoice for per-order customers, dispatch confirmation
     * email) are the same as the run-level button on the chilled-run sheet.
     */
    public function markDispatched(Request $request, Order $order, \App\Services\DispatchService $dispatcher): RedirectResponse
    {
        $this->authorize('update', $order);

        try {
            $result = $dispatcher->dispatch($order, $request->user());
        } catch (\App\Services\DispatchException $e) {
            return back()->with('error', $e->getMessage());
        }

        $success = 'Order '.$order->order_number.' dispatched.';
        $problems = [];

        if ($result->invoiceIssued) {
            $success .= ' Invoice '.$result->invoice->number.' issued — send it from Finance.';
        } elseif ($result->invoice) {
            $success .= ' Already on invoice '.$result->invoice->number.'.';
        } elseif ($result->invoiceError) {
            $problems[] = 'Invoice not issued: '.$result->invoiceError.' The order is in Finance → Awaiting invoice.';
        }

        if ($result->emailSent()) {
            $success .= ' Confirmation emailed to '.$result->emailRow->recipient.'.';
        } elseif ($result->emailSkippedReason) {
            $problems[] = 'Confirmation not sent — '.lcfirst($result->emailSkippedReason);
        } elseif ($result->emailFailed()) {
            $problems[] = 'Confirmation email failed — resend from the order page.';
        }

        $redirect = redirect()->route('orders.show', $order)->with('success', $success);

        return $problems ? $redirect->with('error', implode(' ', $problems)) : $redirect;
    }

    public function markDelivered(Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        if ($order->status !== 'dispatched') {
            return back()->with('error', 'Only dispatched orders can be marked as delivered.');
        }

        $order->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        return redirect()->route('orders.show', $order)
            ->with('success', 'Order '.$order->order_number.' marked as delivered.');
    }

    /**
     * Add a line to an existing order. Allowed while the order is still open
     * (pending → ready); blocked once dispatched/delivered/cancelled. If the
     * variant is already on the order the quantity is merged into that line
     * rather than creating a duplicate. Adding unpicked stock to a ready order
     * reopens picking (ready → preparing); fulfilling it flips it back via
     * OrderAllocationController::markPickingComplete().
     */
    public function storeItem(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        if (in_array($order->status, ['dispatched', 'delivered', 'cancelled'], true)) {
            return redirect()->route('orders.show', $order)
                ->with('error', "Items can't be added to a {$order->status} order.");
        }

        if ($blocked = $this->blockedByInvoice($order, 'adding an item')) {
            return $blocked;
        }

        $validated = $request->validate([
            'product_variant_id' => 'required|exists:product_variants,id',
            'quantity' => 'nullable|integer|min:1',
        ]);
        // The box is blank with a placeholder of 1 (F-22); blank means 1.
        $validated['quantity'] = (int) ($validated['quantity'] ?? 1);

        DB::transaction(function () use ($order, $validated) {
            $variant = ProductVariant::findOrFail($validated['product_variant_id']);

            // Merge: the posted quantity adds to any existing line for this
            // variant. Allocated/fulfilled are untouched, so the line becomes
            // under-allocated and its picker reappears.
            $existing = $order->orderItems()
                ->where('product_variant_id', $variant->id)
                ->first();

            $this->applyLineQuantity($order, $variant, $validated['quantity'] + (int) ($existing->quantity_ordered ?? 0));

            $order->calculateTotals();

            // The order had been fully picked; new work means it's no longer ready.
            if ($order->status === 'ready') {
                $order->update(['status' => 'preparing']);
            }
        });

        $lineId = $order->orderItems()->where('product_variant_id', $validated['product_variant_id'])->value('id');

        return redirect()->route('orders.show', $order)->withFragment('line-'.$lineId)
            ->with('success', 'Item added to order.');
    }

    /**
     * Change a line's ordered quantity. Increasing just raises the quantity
     * (the inline picker then shows the shortfall); decreasing unwinds the
     * difference via OrderItem::releaseUnits() — releasing reservations first
     * and returning any picked stock to its batch. Blocked once the order is
     * dispatched/delivered/cancelled.
     */
    public function updateItem(Request $request, Order $order, OrderItem $orderItem): RedirectResponse
    {
        $this->authorize('update', $order);
        abort_if($orderItem->order_id !== $order->id, 404);

        if (in_array($order->status, ['dispatched', 'delivered', 'cancelled'], true)) {
            return redirect()->route('orders.show', $order)
                ->with('error', "Items can't be changed on a {$order->status} order.");
        }

        if ($blocked = $this->blockedByInvoice($order, 'changing an item')) {
            return $blocked;
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $newQty = (int) $validated['quantity'];
        $oldQty = (int) $orderItem->quantity_ordered;

        if ($newQty === $oldQty) {
            return redirect()->route('orders.show', $order)->withFragment('line-'.$orderItem->id)->with('success', 'No change to quantity.');
        }

        DB::transaction(function () use ($order, $orderItem, $newQty) {
            // Decreases release the difference (reserved first, then picked).
            $this->setLineQuantity($orderItem, $newQty);

            $order->calculateTotals();
            $order->reconcilePickingStatus();
        });

        return redirect()->route('orders.show', $order)->withFragment('line-'.$orderItem->id)
            ->with('success', 'Item quantity updated.');
    }

    /**
     * Remove a line from an order, returning any reserved/picked stock to its
     * batch first (the FK cascade alone would strand it). An order must keep at
     * least one line; blocked once dispatched/delivered/cancelled.
     */
    public function destroyItem(Request $request, Order $order, OrderItem $orderItem): RedirectResponse
    {
        $this->authorize('update', $order);
        abort_if($orderItem->order_id !== $order->id, 404);

        if (in_array($order->status, ['dispatched', 'delivered', 'cancelled'], true)) {
            return redirect()->route('orders.show', $order)
                ->with('error', "Items can't be removed from a {$order->status} order.");
        }

        if ($blocked = $this->blockedByInvoice($order, 'removing an item')) {
            return $blocked;
        }

        // An order can't go empty: removing its only line cancels the order
        // instead (keeping the line as history), returning any committed stock.
        if ($order->orderItems()->count() <= 1) {
            DB::transaction(function () use ($order) {
                $this->cancelOrderKeepingLines($order);
            });

            return redirect()->route('orders.show', $order)
                ->with('success', 'That was the only item, so order '.$order->order_number.' has been cancelled. Any reserved or picked stock has been returned to its batch.');
        }

        DB::transaction(function () use ($order, $orderItem) {
            // Return all committed stock (reserved + picked) before deleting.
            $this->removeLine($orderItem);

            $order->calculateTotals();
            $order->reconcilePickingStatus();
        });

        // The line is gone; land on the items panel it was in.
        return redirect()->route('orders.show', $order)->withFragment('order-items')
            ->with('success', 'Item removed. Any picked stock has been returned to its batch.');
    }

    /**
     * Office-side short close — the counterpart to PickingController::markShort()
     * for lines the office already knows can't be filled (the "No available
     * stock" dead end on the inline allocation block). Releases the line's
     * reservation, settles it so the order can reach ready, and drops the
     * unsupplied units out of the invoice.
     */
    public function shortCloseItem(Request $request, Order $order, OrderItem $orderItem): RedirectResponse
    {
        $this->authorize('update', $order);
        abort_if($orderItem->order_id !== $order->id, 404);

        if (in_array($order->status, ['dispatched', 'delivered', 'cancelled'], true)) {
            return redirect()->route('orders.show', $order)
                ->with('error', "Items can't be changed on a {$order->status} order.");
        }

        if ($blocked = $this->blockedByInvoice($order, 'changing an item')) {
            return $blocked;
        }

        if ($orderItem->isFullyFulfilled()) {
            return redirect()->route('orders.show', $order)->withFragment('line-'.$orderItem->id)
                ->with('error', 'That line is already fully fulfilled — nothing to mark short.');
        }

        $validated = $request->validate(['short_reason' => 'nullable|string|max:255']);
        $shortQty = $orderItem->quantity_remaining;

        DB::transaction(function () use ($order, $orderItem, $validated) {
            $orderItem->closeShort(($validated['short_reason'] ?? null) ?: null);
            $order->calculateTotals();
            $order->reconcilePickingStatus();
        });

        return redirect()->route('orders.show', $order)->withFragment('line-'.$orderItem->id)
            ->with('success', "Line closed short — {$shortQty} units released back to stock, not supplied and not billed.");
    }

    /**
     * Reopen a short-closed line: the shortfall becomes outstanding work again
     * and the order drops back to preparing. Does not re-reserve stock — run
     * auto-allocate for that.
     */
    public function reopenItem(Order $order, OrderItem $orderItem): RedirectResponse
    {
        $this->authorize('update', $order);
        abort_if($orderItem->order_id !== $order->id, 404);

        if (in_array($order->status, ['dispatched', 'delivered', 'cancelled'], true)) {
            return redirect()->route('orders.show', $order)
                ->with('error', "Items can't be changed on a {$order->status} order.");
        }

        if ($blocked = $this->blockedByInvoice($order, 'changing an item')) {
            return $blocked;
        }

        DB::transaction(function () use ($order, $orderItem) {
            $orderItem->reopenShort();
            $order->calculateTotals();
            $order->reconcilePickingStatus();
        });

        return redirect()->route('orders.show', $order)->withFragment('line-'.$orderItem->id)
            ->with('success', 'Line reopened — it needs picking again.');
    }
}
