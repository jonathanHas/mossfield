<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AllocatesOrderStock;
use App\Http\Controllers\Concerns\MutatesOrderLines;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\ProductVariant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Chilled run sheet — the digital twin of the delivery-run spreadsheet, and
 * (for office/admin) the order-entry surface that replaces it. One tab per
 * active run; the sheet lists the run's customers in stop order, paired with
 * each customer's order for the run's date: fixed milk/yoghurt quantity
 * columns (all active variants), dynamic cheese columns (variants present on
 * the run's orders), a notes column, and blue-crate totals.
 *
 * Editing is per row (?edit={customer}): quantities become inputs, previous
 * orders can be recalled as prefills, and extra (cheese) lines added via a
 * select. Saving diff-applies against the customer's order for that date —
 * creating a pending order when none exists — via the shared
 * MutatesOrderLines invariants (stock unwind on decrease/remove, cancel
 * keeps lines as history). Entry is office/admin only (OrderPolicy
 * create/update); factory keeps the read view + the loaded tick
 * (OrderPolicy::load).
 *
 * Quantities only — no € appears on this page, so the see-financials gate is
 * intentionally unused. Don't add price columns without re-gating.
 *
 * Orders due that day from customers NOT assigned to a run are deliberately
 * out of scope here — they live on /picking and /orders.
 */
class ChilledRunController extends Controller
{
    use AllocatesOrderStock;
    use MutatesOrderLines;

    /** Order statuses a row can still be edited in. */
    private const OPEN_STATUSES = ['pending', 'confirmed', 'preparing', 'ready'];

    /**
     * Statuses in which an order can be auto-allocated from the sheet.
     *
     * Pending is deliberately excluded: markPickingStarted() only promotes
     * from confirmed, so a pending order would sit on reservations while
     * staying invisible to /picking. It also keeps the day's flow honest —
     * enter, Confirm all, Allocate all, pick — and matches the gate on the
     * order page's own banner (orders/show.blade.php).
     */
    private const ALLOCATABLE_STATUSES = ['confirmed', 'preparing'];

    /**
     * Day tabs + the selected run's sheet. ?run= selects a run, ?date= anchors
     * the week (any date inside the wanted ISO week), ?edit= puts one stop
     * row into entry mode.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', DeliveryRun::class);

        $runs = DeliveryRun::active()
            ->withCount(['customers as stops_count' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($runs->isEmpty()) {
            return view('chilled-runs.index', [
                'runs' => $runs,
                'activeRun' => null,
                'sheet' => null,
                'weekAnchor' => CarbonImmutable::now(),
            ]);
        }

        $weekAnchor = $request->filled('date')
            ? CarbonImmutable::parse($request->query('date'))
            : CarbonImmutable::now();

        $activeRun = $runs->firstWhere('id', (int) $request->query('run')) ?? $runs->first();

        $editCustomerId = $request->user()->can('create', Order::class)
            ? (int) $request->query('edit')
            : 0;

        $sheet = $this->buildSheet($activeRun, $weekAnchor, $editCustomerId);

        return view('chilled-runs.index', compact('runs', 'activeRun', 'sheet', 'weekAnchor'));
    }

    /**
     * Toggle an order's "loaded onto van" tick. Plain POST + redirect back to
     * the sheet, preserving the run/date context from hidden inputs.
     */
    public function toggleLoaded(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('load', $order);

        $order->update(['loaded_at' => $order->loaded_at ? null : now()]);

        return redirect()->route('chilled-runs.index', array_filter([
            'run' => $request->input('run', $order->customer?->delivery_run_id),
            'date' => $request->input('date'),
        ]))->with('success', $order->loaded_at
            ? "{$order->customer->name} marked loaded."
            : "{$order->customer->name} unmarked — back on the to-load list.");
    }

    /**
     * Confirm every pending order on the selected run/date — moving them onto
     * the picking queue (/picking lists confirmed/preparing/ready). Orders
     * entered on the sheet start as pending; this is the "send the day's
     * orders to the floor" action. Office/admin only.
     */
    public function confirmAll(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'run' => 'required|exists:delivery_runs,id',
            'date' => 'nullable|date',
        ]);

        $run = DeliveryRun::findOrFail($validated['run']);
        $runDate = $run->dateFor($request->filled('date')
            ? CarbonImmutable::parse($validated['date'])
            : CarbonImmutable::now());

        $pending = Order::whereIn('customer_id', $run->customers()->where('is_active', true)->pluck('id'))
            ->whereDate('delivery_date', $runDate->toDateString())
            ->where('status', 'pending')
            ->get();

        foreach ($pending as $order) {
            $this->authorize('update', $order);
            $order->update(['status' => 'confirmed']);
        }

        return redirect()->route('chilled-runs.index', array_filter([
            'run' => $run->id,
            'date' => $request->input('date'),
        ]))->with('success', $pending->isEmpty()
            ? 'No pending orders to confirm on this run.'
            : $pending->count().' '.str('order')->plural($pending->count()).' confirmed — now on the picking queue.');
    }

    /**
     * Reserve available stock (FIFO, oldest batch first) for every allocatable
     * order on the selected run/date — the step between "Confirm all" and the
     * floor picking the load. Office/admin only.
     */
    public function allocateAll(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'run' => 'required|exists:delivery_runs,id',
            'date' => 'nullable|date',
        ]);

        $run = DeliveryRun::findOrFail($validated['run']);
        $runDate = $run->dateFor($request->filled('date')
            ? CarbonImmutable::parse($validated['date'])
            : CarbonImmutable::now());

        $orders = Order::with('orderItems.productVariant')
            ->whereIn('customer_id', $run->customers()->where('is_active', true)->pluck('id'))
            ->whereDate('delivery_date', $runDate->toDateString())
            ->whereIn('status', array_merge(self::ALLOCATABLE_STATUSES, ['pending']))
            ->get();

        $stats = $this->freshAllocationStats();

        foreach ($orders as $order) {
            $this->allocateOrderInto($order, $stats);
        }

        [$key, $message] = $this->allocationFlash($stats);

        return redirect()->route('chilled-runs.index', array_filter([
            'run' => $run->id,
            'date' => $request->input('date'),
        ]))->with($key, $message);
    }

    /**
     * Same as allocateAll() for a single stop. Unlike saveStop() this doesn't
     * refuse when the customer has two orders that day — allocating across
     * both is unambiguous; only editing them inline is.
     */
    public function allocateStop(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'run' => 'required|exists:delivery_runs,id',
            'date' => 'nullable|date',
        ]);

        $run = DeliveryRun::findOrFail($validated['run']);
        $runDate = $run->dateFor($request->filled('date')
            ? CarbonImmutable::parse($validated['date'])
            : CarbonImmutable::now());

        $stats = $this->freshAllocationStats();

        foreach ($this->ordersFor($customer, $runDate) as $order) {
            $this->allocateOrderInto($order, $stats);
        }

        [$key, $message] = $this->allocationFlash($stats);

        return redirect()->to(route('chilled-runs.index', array_filter([
            'run' => $run->id,
            'date' => $request->input('date'),
        ])).'#stop-'.$customer->id)->with($key, "{$customer->name}: {$message}");
    }

    /** @return array{orders: int, units: int, short: int, skipped_pending: int, already: int} */
    private function freshAllocationStats(): array
    {
        return ['orders' => 0, 'units' => 0, 'short' => 0, 'skipped_pending' => 0, 'already' => 0];
    }

    /**
     * Run one order through the shared FIFO allocator, folding the outcome
     * into the caller's running totals.
     */
    private function allocateOrderInto(Order $order, array &$stats): void
    {
        if ($order->status === 'pending') {
            $stats['skipped_pending']++;

            return;
        }

        if (! in_array($order->status, self::ALLOCATABLE_STATUSES, true)) {
            return;
        }

        $order->loadMissing('orderItems');

        // NB isFullyAllocated() is true for an order with no lines, so the
        // emptiness check has to come first.
        if ($order->orderItems->isEmpty() || $order->isFullyAllocated()) {
            $stats['already']++;

            return;
        }

        $result = $this->autoAllocateOrder($order);

        if ($result['units'] > 0) {
            $stats['orders']++;
            $stats['units'] += $result['units'];
        }

        if ($result['lines_short'] > 0) {
            $stats['short']++;
        }
    }

    /**
     * Turn the accumulated outcome into one flash. Partial results are named
     * rather than swallowed — the operator needs to know *why* nothing moved.
     *
     * @return array{0: string, 1: string}
     */
    private function allocationFlash(array $stats): array
    {
        if ($stats['units'] === 0) {
            if ($stats['skipped_pending'] > 0) {
                return ['error', "Nothing allocated — {$stats['skipped_pending']} "
                    .str('order')->plural($stats['skipped_pending']).' still pending. Confirm them first.'];
            }

            if ($stats['short'] > 0) {
                return ['error', 'Nothing could be allocated — no free stock for the outstanding lines.'];
            }

            return ['success', 'Nothing to allocate — every order here is already covered.'];
        }

        $message = "Allocated {$stats['units']} ".str('unit')->plural($stats['units'])
            ." across {$stats['orders']} ".str('order')->plural($stats['orders']).'.';

        if ($stats['short'] > 0) {
            $message .= " {$stats['short']} still short of stock.";
        }

        if ($stats['skipped_pending'] > 0) {
            $message .= " {$stats['skipped_pending']} pending "
                .str('order')->plural($stats['skipped_pending']).' skipped — confirm them first.';
        }

        return ['success', $message];
    }

    /**
     * Save a stop's order from the row-edit form: qty[variant_id] => units
     * for every visible column plus any added lines. Diff-applies against the
     * customer's order for the run's date — creating a pending order when
     * none exists, removing lines posted as zero, and cancelling (keeping
     * lines as history) when the edit would leave the order empty.
     */
    public function saveStop(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'run' => 'required|exists:delivery_runs,id',
            'date' => 'nullable|date',
            'qty' => 'array',
            'qty.*' => 'nullable|integer|min:0|max:65000',
            'customer_reference' => 'nullable|string|max:255',
        ]);

        $run = DeliveryRun::findOrFail($validated['run']);
        $runDate = $run->dateFor($request->filled('date')
            ? CarbonImmutable::parse($validated['date'])
            : CarbonImmutable::now());

        $orders = $this->ordersFor($customer, $runDate);
        abort_if($orders->count() > 1, 409, 'Multiple orders for this stop — edit them on the order pages.');
        $order = $orders->first();

        $backToSheet = fn () => redirect()->route('chilled-runs.index', array_filter([
            'run' => $run->id,
            'date' => $request->input('date'),
        ]));

        if ($order && ! in_array($order->status, self::OPEN_STATUSES, true)) {
            return $backToSheet()->with('error', "This order is {$order->status} and can't be edited here.");
        }

        // New orders need create; edits need update. Both are office/admin —
        // factory is also denied at the route middleware.
        $this->authorize(...$order ? ['update', $order] : ['create', Order::class]);

        $posted = collect($validated['qty'] ?? [])
            ->mapWithKeys(fn ($qty, $variantId) => [(int) $variantId => (int) $qty]);
        $postedPositive = $posted->filter(fn ($qty) => $qty > 0);
        // The ref input is always in the DOM (hidden behind the toggle), so an
        // untouched/blank one posts ''. Normalise empties to null.
        $reference = isset($validated['customer_reference']) && trim($validated['customer_reference']) !== ''
            ? trim($validated['customer_reference'])
            : null;

        // A reference with no quantities and no existing order is intentionally
        // not persisted — an order with no lines is meaningless.
        if (! $order && $postedPositive->isEmpty()) {
            return $backToSheet()->with('success', 'Nothing to save — no quantities entered.');
        }

        DB::transaction(function () use (&$order, $customer, $runDate, $posted, $postedPositive, $reference) {
            // The edit zeroes everything and no lines exist outside the posted
            // set: an order can't go empty, so cancel it (keeping the lines as
            // history, returning all committed stock).
            if ($order && $postedPositive->isEmpty()
                && $order->orderItems()->whereNotIn('product_variant_id', $posted->keys())->doesntExist()) {
                $this->cancelOrderKeepingLines($order);

                return;
            }

            if (! $order) {
                $order = Order::create([
                    'customer_id' => $customer->id,
                    'order_date' => now()->toDateString(),
                    'delivery_date' => $runDate->toDateString(),
                    'delivery_charge' => $customer->currentDeliveryCharge(),
                    'delivery_charge_percent' => $customer->deliveryChargePercent(),
                    'status' => 'pending',
                    'payment_status' => 'pending',
                ]); // order_number auto-generated via Order::boot()
            }

            // Persist the (possibly cleared) customer reference. calculateTotals()
            // below saves the model, so this dirty attribute rides along.
            $order->customer_reference = $reference;

            $existingByVariant = $order->orderItems()->get()->groupBy('product_variant_id');

            foreach ($posted as $variantId => $qty) {
                if ($qty <= 0) {
                    // Zero where line(s) exist => remove (with stock unwind).
                    foreach ($existingByVariant->get($variantId, collect()) as $line) {
                        $this->removeLine($line);
                    }

                    continue;
                }

                $this->applyLineQuantity($order, ProductVariant::findOrFail($variantId), $qty);
            }

            $order->calculateTotals();
            $order->reconcilePickingStatus();
        });

        $message = $order->status === 'cancelled'
            ? "Order for {$customer->name} cancelled — all quantities cleared. Any reserved or picked stock has been returned."
            : "Order for {$customer->name} saved ({$order->order_number}).";

        return $backToSheet()->with('success', $message);
    }

    /**
     * Build the run sheet: rows (customer + that day's orders + editability),
     * fixed milk/yoghurt columns (all active variants), dynamic cheese
     * columns, per-cell quantity map, crate totals, loaded progress, and —
     * when a row is in edit mode — the order-history prefill payload.
     */
    private function buildSheet(DeliveryRun $run, CarbonImmutable $weekAnchor, int $editCustomerId = 0): array
    {
        $runDate = $run->dateFor($weekAnchor);

        $customers = $run->customers()->where('is_active', true)->get();

        $ordersByCustomer = Order::with(['orderItems.productVariant.product'])
            ->whereIn('customer_id', $customers->pluck('id'))
            ->whereDate('delivery_date', $runDate->toDateString())
            ->where('status', '!=', 'cancelled')
            ->get()
            ->groupBy('customer_id');

        // A row is editable when there's at most one order for the date and
        // it (if present) is still open. With 2+ orders the view shows an
        // "edit on the order page" link instead.
        $rows = $customers->map(function ($customer) use ($ordersByCustomer) {
            $orders = $ordersByCustomer->get($customer->id, collect());
            $items = $orders->flatMap->orderItems;

            // Short-closed units come out of "ordered" for STATS ONLY — they'll
            // never be allocated or picked, so leaving them in would peg a
            // settled stop at "partial" and the run's progress bar below 100%
            // for good. The entry grid ($qtyMap below) keeps raw
            // quantity_ordered — that's what the customer actually asked for.
            $short = (int) $items->sum('quantity_short');
            $ordered = (int) $items->sum('quantity_ordered') - $short;
            $allocated = (int) $items->sum('quantity_allocated');
            $picked = (int) $items->sum('quantity_fulfilled');

            // quantity_allocated/_fulfilled are columns on order_items and the
            // orders are already eager-loaded, so all of this is in-memory —
            // no query per row.
            $canAllocate = $orders->contains(fn ($order) => in_array($order->status, self::ALLOCATABLE_STATUSES, true)
                && $order->orderItems->isNotEmpty()
                && ! $order->isFullyAllocated());

            return [
                'customer' => $customer,
                'orders' => $orders,
                'editable' => $orders->count() <= 1
                    && ($orders->isEmpty() || in_array($orders->first()->status, self::OPEN_STATUSES, true)),
                'stats' => [
                    'ordered' => $ordered,
                    'allocated' => $allocated,
                    'picked' => $picked,
                    'short' => $short,
                    'allocState' => $items->isEmpty() ? 'none'
                        : ($allocated >= $ordered ? 'allocated' : ($allocated > 0 ? 'partial' : 'unallocated')),
                    // Same vocabulary as the /picking queue.
                    'pickState' => $orders->isNotEmpty() && $orders->every(fn ($o) => $o->status === 'ready')
                        ? 'ready'
                        : ($picked > 0 ? 'picking' : 'todo'),
                    'canAllocate' => $canAllocate,
                ],
            ];
        });

        $allItems = $rows->flatMap(fn ($row) => $row['orders']->flatMap->orderItems);
        $typeRank = ['milk' => 0, 'yoghurt' => 1];

        // Fixed columns: ALL active milk/yoghurt variants — stable entry
        // targets whether or not anyone ordered them this week. (Sizes like
        // "1L"/"2L" and "250g"/"500g" sort correctly lexically; revisit with
        // a numeric parse if a "10L" churn variant ever lands.)
        $fixedCols = ProductVariant::with('product')
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true)->whereIn('type', ['milk', 'yoghurt']))
            ->get()
            ->sortBy(fn ($variant) => [
                $typeRank[$variant->product->type],
                $variant->size ?? '',
                $variant->name,
            ])
            ->values();

        $milkCols = $fixedCols->filter(fn ($v) => $v->product->type === 'milk')->values();
        $yogCols = $fixedCols->filter(fn ($v) => $v->product->type === 'yoghurt')->values();

        // Dynamic cheese columns: variants actually ordered on this run/date.
        $cheeseCols = $allItems
            ->map(fn ($item) => $item->productVariant)
            ->filter(fn ($variant) => $variant && $variant->product->type === 'cheese')
            ->unique('id')
            ->sortBy(fn ($variant) => [$variant->product->name, $variant->size ?? '', $variant->name])
            ->values();

        $columnIds = $fixedCols->pluck('id')->merge($cheeseCols->pluck('id'))->flip();

        // Per-cell quantities: [customer_id][variant_id] => units (sums across
        // multiple same-day orders for the same customer).
        // $allocatedByVariant rides along in the same pass — it's what the
        // stock footer compares against free stock (see $stock below).
        $qtyMap = [];
        $allocatedByVariant = [];
        $shortByVariant = [];
        foreach ($rows as $row) {
            foreach ($row['orders'] as $order) {
                foreach ($order->orderItems as $item) {
                    if (isset($columnIds[$item->product_variant_id])) {
                        $qtyMap[$row['customer']->id][$item->product_variant_id] =
                            ($qtyMap[$row['customer']->id][$item->product_variant_id] ?? 0) + $item->quantity_ordered;
                        $allocatedByVariant[$item->product_variant_id] =
                            ($allocatedByVariant[$item->product_variant_id] ?? 0) + $item->quantity_allocated;
                        $shortByVariant[$item->product_variant_id] =
                            ($shortByVariant[$item->product_variant_id] ?? 0) + $item->quantity_short;
                    }
                }
            }
        }

        // Notes column: order notes only — cheese has its own columns now.
        $extras = [];
        foreach ($rows as $row) {
            $extras[$row['customer']->id] = $row['orders']->pluck('notes')->filter()->implode(' · ');
        }

        // Customer reference per stop (first set reference) — for display and edit prefill.
        $references = [];
        foreach ($rows as $row) {
            $references[$row['customer']->id] = $row['orders']->pluck('customer_reference')->filter()->first();
        }

        // Footer rows: units / full blue crates / remainder outside crates.
        // Crates only make sense for milk/yoghurt (cheese has no case_size) —
        // cheese columns carry units with null crate cells (view renders "—").
        $totals = [];
        foreach ($fixedCols->merge($cheeseCols) as $variant) {
            $units = $rows->sum(fn ($row) => $qtyMap[$row['customer']->id][$variant->id] ?? 0);
            $isCrated = isset($typeRank[$variant->product->type]);
            $caseSize = $variant->effective_case_size;
            $totals[$variant->id] = [
                'units' => $units,
                'crates' => $isCrated ? intdiv($units, $caseSize) : null,
                'extra' => $isCrated ? $units % $caseSize : null,
            ];
        }

        // Stock footer: free stock per column, and whether this run's still-to-
        // allocate demand exceeds it. "Free" is what's genuinely left in the
        // fridge — net of every reservation, this run's included — so once
        // Allocate all succeeds the shortfall clears and the row re-reads as
        // what's left for other days.
        $stock = $this->stockByVariant($fixedCols->merge($cheeseCols), $totals, $allocatedByVariant, $shortByVariant);

        $milkUnits = $milkCols->sum(fn ($v) => $totals[$v->id]['units']);
        $milkCrates = $milkCols->sum(fn ($v) => $totals[$v->id]['crates']);
        $yogUnits = $yogCols->sum(fn ($v) => $totals[$v->id]['units']);
        $yogCrates = $yogCols->sum(fn ($v) => $totals[$v->id]['crates']);
        $cheeseUnits = $cheeseCols->sum(fn ($v) => $totals[$v->id]['units']);

        // Pending orders awaiting "Confirm all" (entry creates them pending).
        $pendingCount = $rows->sum(fn ($row) => $row['orders']->where('status', 'pending')->count());

        // Loaded progress counts only stops that have an order to load.
        $stopsWithOrders = $rows->filter(fn ($row) => $row['orders']->isNotEmpty());
        $loadedCount = $stopsWithOrders
            ->filter(fn ($row) => $row['orders']->every(fn ($o) => $o->loaded_at !== null))
            ->count();
        $loadableCount = $stopsWithOrders->count();

        // Allocation progress — same denominator set as the loaded bar, so the
        // two read consistently.
        $allocatableCount = $rows->sum(fn ($row) => $row['orders']->filter(
            fn ($o) => in_array($o->status, self::ALLOCATABLE_STATUSES, true)
                && $o->orderItems->isNotEmpty()
                && ! $o->isFullyAllocated()
        )->count());
        $allocatedStops = $stopsWithOrders->filter(
            fn ($row) => $row['stats']['ordered'] > 0
                && $row['stats']['allocated'] >= $row['stats']['ordered']
        )->count();
        $orderedUnits = (int) $rows->sum(fn ($row) => $row['stats']['ordered']);
        $allocatedUnits = (int) $rows->sum(fn ($row) => $row['stats']['allocated']);

        // Edit-mode context for the one row being edited (if any).
        $editRow = $editCustomerId
            ? $rows->first(fn ($row) => $row['customer']->id === $editCustomerId && $row['editable'])
            : null;

        return [
            'runDate' => $runDate,
            'rows' => $rows,
            'qtyMap' => $qtyMap,
            'extras' => $extras,
            'references' => $references,
            'milkCols' => $milkCols,
            'yogCols' => $yogCols,
            'cheeseCols' => $cheeseCols,
            'totals' => $totals,
            'stock' => $stock,
            'milkUnits' => $milkUnits,
            'milkCrates' => $milkCrates,
            'yogUnits' => $yogUnits,
            'yogCrates' => $yogCrates,
            'cheeseUnits' => $cheeseUnits,
            'cratesTotal' => $milkCrates + $yogCrates,
            'pendingCount' => $pendingCount,
            'loadedCount' => $loadedCount,
            'loadableCount' => $loadableCount,
            'loadedPct' => $loadableCount > 0 ? (int) round($loadedCount / $loadableCount * 100) : 0,
            'allocatableCount' => $allocatableCount,
            'allocatedStops' => $allocatedStops,
            'allocStopsTotal' => $loadableCount,
            'allocatedPct' => $loadableCount > 0 ? (int) round($allocatedStops / $loadableCount * 100) : 0,
            'orderedUnits' => $orderedUnits,
            'allocatedUnits' => $allocatedUnits,
            'editCustomerId' => $editRow ? $editCustomerId : 0,
            'editQuantities' => $editRow ? ($qtyMap[$editCustomerId] ?? []) : [],
            'editCustomerReference' => $editRow ? ($references[$editCustomerId] ?? null) : null,
            'history' => $editRow ? $this->historyFor($editRow['customer'], $editRow['orders']->first()) : [],
            'addLineVariants' => $editRow ? $this->addLineVariants($fixedCols, $cheeseCols) : collect(),
        ];
    }

    /**
     * Free stock per column variant, plus whether this run's outstanding
     * demand exceeds it.
     *
     * One query for every column (plus the two eager loads), NOT a read of
     * BatchItem::available_quantity per row — that accessor fires a SUM on
     * every access (see getHoldableQuantityAttribute), which would be an N+1
     * across the sheet's columns. The arithmetic below mirrors it exactly:
     * remaining − unfulfilled reservations − maturing hold.
     *
     * @param  Collection<int, ProductVariant>  $columns
     * @return array<int, array{free: int, outstanding: int, short: bool}>
     */
    private function stockByVariant(Collection $columns, array $totals, array $allocatedByVariant, array $shortByVariant = []): array
    {
        if ($columns->isEmpty()) {
            return [];
        }

        // No column list on get() — withSum() populates the select via
        // selectSub, so restricting columns here would be ignored and doing it
        // earlier would drop batch_id and break the eager load.
        $byVariant = BatchItem::allocatable()
            ->whereIn('product_variant_id', $columns->pluck('id'))
            ->with('batch.product')
            ->withSum([
                'orderAllocations as reserved_units' => fn ($q) => $q->whereNull('fulfilled_at'),
            ], 'quantity_allocated')
            ->get()
            ->groupBy('product_variant_id');

        $stock = [];

        foreach ($columns as $variant) {
            $free = (int) $byVariant->get($variant->id, collect())
                // The same PHP predicates allocation applies, so the footer can
                // never promise more than Allocate all will actually take.
                ->filter(fn ($batchItem) => $batchItem->isReadyToSell() && ! $batchItem->batch->isExpired())
                ->sum(fn ($batchItem) => max(0, (int) $batchItem->quantity_remaining
                    - (int) ($batchItem->reserved_units ?? 0)
                    - (int) $batchItem->quantity_maturing));

            // Short-closed units are no longer demand — nothing will ever be
            // reserved against them, so leaving them in would light the column
            // red for good. (The Total units / crates rows above deliberately
            // keep the raw ordered figures so they still tie back to the cells
            // in the grid, which is the order-entry surface.)
            $outstanding = max(0, ($totals[$variant->id]['units'] ?? 0)
                - ($allocatedByVariant[$variant->id] ?? 0)
                - ($shortByVariant[$variant->id] ?? 0));

            $stock[$variant->id] = [
                'free' => $free,
                'outstanding' => $outstanding,
                'short' => $outstanding > $free,
            ];
        }

        return $stock;
    }

    /** The customer's non-cancelled orders for one delivery date. */
    private function ordersFor(Customer $customer, CarbonImmutable $date): Collection
    {
        return Order::where('customer_id', $customer->id)
            ->whereDate('delivery_date', $date->toDateString())
            ->where('status', '!=', 'cancelled')
            ->get();
    }

    /**
     * Prefill payload for the row editor: the customer's last 5 non-cancelled
     * orders (any date, excluding the one being edited), each as a label plus
     * a {variant_id: qty} map limited to active variants.
     */
    private function historyFor(Customer $customer, ?Order $excludeOrder): array
    {
        return Order::with('orderItems.productVariant')
            ->where('customer_id', $customer->id)
            ->where('status', '!=', 'cancelled')
            ->when($excludeOrder, fn ($q) => $q->where('id', '!=', $excludeOrder->id))
            ->orderByDesc('delivery_date')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(function ($order) {
                $date = $order->delivery_date ?? $order->order_date;
                $quantities = [];
                foreach ($order->orderItems as $item) {
                    if ($item->productVariant?->is_active) {
                        $quantities[$item->product_variant_id] =
                            ($quantities[$item->product_variant_id] ?? 0) + $item->quantity_ordered;
                    }
                }

                return [
                    'id' => $order->id,
                    'label' => ($date ? $date->format('D d/m') : '—').' · '.$order->order_number,
                    'quantities' => $quantities,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Variants offered by the row editor's "add line" select: active variants
     * of active products that aren't already a column on the sheet (in
     * practice: cheese). Names only — no prices on this page.
     */
    private function addLineVariants(Collection $fixedCols, Collection $cheeseCols): Collection
    {
        $columnIds = $fixedCols->pluck('id')->merge($cheeseCols->pluck('id'));

        return ProductVariant::with('product')
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->whereNotIn('id', $columnIds)
            ->get()
            ->sortBy(fn ($v) => [$v->product->name, $v->name])
            ->groupBy('product.name');
    }
}
