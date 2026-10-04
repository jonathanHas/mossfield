<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidatesFulfilmentWeights;
use App\Models\BatchItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Mobile-first picking surface for the shop floor. Factory users (plus
 * admin/office) work a phone-sized queue: pick each line from a chosen batch
 * in one tap — allocate + fulfil in a single transaction via
 * OrderItem::pickFromBatchItem(). Write access is gated by the narrow
 * OrderPolicy::fulfill ability, NOT general order update (factory still
 * cannot edit orders, lines, or prices).
 */
class PickingController extends Controller
{
    use ValidatesFulfilmentWeights;

    /** Orders that belong on the picking queue. */
    private const QUEUE_STATUSES = ['confirmed', 'preparing', 'ready'];

    /**
     * Today's queue — every order awaiting or in picking, plus freshly
     * readied ones, sorted by delivery date (undated last).
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Order::class);

        $queue = Order::with(['customer', 'orderItems'])
            ->whereIn('status', self::QUEUE_STATUSES)
            ->orderByRaw('delivery_date is null')
            ->orderBy('delivery_date')
            ->orderBy('created_at')
            ->get();

        // A ready order from an earlier day is the office's to dispatch, not
        // the floor's to pick — it goes in a collapsed bucket rather than
        // dragging the day picker back to July (F-30). confirmed/preparing
        // orders with past dates stay: they still need picking.
        $today = today();
        [$stale, $active] = $queue->partition(
            fn ($order) => $order->status === 'ready' && $order->delivery_date && $order->delivery_date->lt($today)
        );

        // One day at a time; undated orders are a trailing "none" bucket.
        $byDay = $active->groupBy(fn ($order) => self::dayKey($order));
        $days = $byDay->keys()->values();
        $selected = $this->selectDay($days, $request->query('date'));
        $orders = $selected !== null ? $byDay->get($selected, collect())->values() : collect();

        $index = $selected !== null ? $days->search($selected) : false;
        $prevDay = $index !== false && $index > 0 ? $days[$index - 1] : null;
        $nextDay = $index !== false && $index < $days->count() - 1 ? $days[$index + 1] : null;

        $pickedUnits = (int) $orders->sum(fn ($order) => $order->orderItems->sum('quantity_fulfilled'));
        // Short-closed units leave the denominator — they're never going to be
        // picked, so counting them would peg the day's bar below 100% for good.
        $totalUnits = (int) $orders->sum(
            fn ($order) => $order->orderItems->sum(fn ($item) => $item->quantity_ordered - $item->quantity_short)
        );

        return view('picking.index', compact(
            'orders', 'pickedUnits', 'totalUnits', 'selected', 'prevDay', 'nextDay', 'stale'
        ));
    }

    /** The queue's day bucket for an order: its delivery date, or "none". */
    public static function dayKey(Order $order): string
    {
        return $order->delivery_date?->toDateString() ?? 'none';
    }

    /**
     * ?date= when it names a day with work; else today; else the nearest
     * future day; else the latest past day; else the undated bucket.
     */
    private function selectDay(Collection $days, ?string $requested): ?string
    {
        if ($requested !== null && $days->contains($requested)) {
            return $requested;
        }

        $today = today()->toDateString();
        $dated = $days->reject(fn ($day) => $day === 'none');

        return $dated->first(fn ($day) => $day === $today)
            ?? $dated->first(fn ($day) => $day > $today)
            ?? $dated->last(fn ($day) => $day < $today)
            ?? $days->first();
    }

    public function show(Order $order): View|RedirectResponse
    {
        $this->authorize('fulfill', $order);

        if ($redirect = $this->ensureInQueue($order)) {
            return $redirect;
        }

        $order->load([
            'customer',
            'orderItems.productVariant.product',
            'orderItems.orderAllocations.batchItem.batch',
        ]);

        // Settled, not fulfilled: a short-closed line must drop out of the
        // queue or the picker is looped back to an impossible line forever.
        $nextItem = $order->orderItems->first(fn ($item) => ! $item->isSettled());

        // FIFO batch suggestion for the "next up" hint.
        $suggestedBatch = $nextItem
            ? $this->batchOptionsFor($nextItem)->first(fn ($option) => $option['max'] > 0)
            : null;

        // The order after this one in the queue, for the ready screen's "next up" card.
        // Same delivery day first, then the queue order as before.
        $nextOrder = null;
        if ($order->isPickingComplete()) {
            $candidates = Order::with('orderItems')
                ->whereIn('status', ['confirmed', 'preparing'])
                ->whereKeyNot($order->id)
                ->orderByRaw('delivery_date is null')
                ->orderBy('delivery_date')
                ->orderBy('created_at')
                ->get();
            $nextOrder = $candidates->first(fn ($candidate) => self::dayKey($candidate) === self::dayKey($order))
                ?? $candidates->first();
        }

        return view('picking.show', compact('order', 'nextItem', 'suggestedBatch', 'nextOrder'));
    }

    /**
     * Pick-item screen: fixed-qty stepper or variable-weight entry, plus the
     * FIFO batch chooser. Fully-picked lines show their state with an Undo.
     */
    public function item(Order $order, OrderItem $orderItem): View|RedirectResponse
    {
        $this->authorize('fulfill', $order);
        abort_if($orderItem->order_id !== $order->id, 404);

        if ($redirect = $this->ensureInQueue($order)) {
            return $redirect;
        }

        $order->load('orderItems.productVariant.product');
        $orderItem->load(['productVariant.product', 'orderAllocations.batchItem.batch']);

        $batchOptions = $this->batchOptionsFor($orderItem);

        $position = $order->orderItems->search(fn ($item) => $item->id === $orderItem->id) + 1;
        // Skip walks forward and wraps: the next unsettled line after this
        // one, else the first before it (F-31 — it used to bounce to line 1).
        $lines = $order->orderItems->values();
        $skipTo = $lines->slice($position)->concat($lines->take($position - 1))
            ->first(fn ($item) => ! $item->isSettled());

        return view('picking.item', compact('order', 'orderItem', 'batchOptions', 'position', 'skipTo'));
    }

    /**
     * The one-tap pick: allocate from the chosen batch and record fulfilment
     * (with weight for variable-weight lines) in a single transaction.
     */
    public function pick(Request $request, Order $order, OrderItem $orderItem): RedirectResponse
    {
        $this->authorize('fulfill', $order);
        abort_if($orderItem->order_id !== $order->id, 404);

        if ($redirect = $this->ensureInQueue($order)) {
            return $redirect;
        }

        $this->normaliseWeightInput($request);

        $validated = $request->validate(array_merge([
            'batch_item_id' => 'required|exists:batch_items,id',
            'quantity' => 'required|integer|min:1|max:'.$orderItem->quantity_remaining,
        ], $this->fulfilmentWeightRules($orderItem)), $this->fulfilmentWeightMessages($orderItem));

        $batchItem = BatchItem::with('batch')->findOrFail($validated['batch_item_id']);

        if ($batchItem->product_variant_id !== $orderItem->product_variant_id) {
            return redirect()->route('picking.item', [$order, $orderItem])
                ->withErrors(['batch_item_id' => 'Selected batch does not match this product.']);
        }

        $quantity = (int) $validated['quantity'];
        [$weight, $unitWeights] = $this->resolveFulfilmentWeight($validated, $orderItem);

        if (! $orderItem->pickFromBatchItem($batchItem, $quantity, $weight, $unitWeights)) {
            return redirect()->route('picking.item', [$order, $orderItem])
                ->withErrors(['quantity' => 'Could not pick from this batch — stock may have changed or the line is already reserved against another batch. Check the batch list and try again.']);
        }

        // First pick bumps a confirmed order into preparing; the canonical
        // reconcile rule then promotes preparing → ready when fully picked.
        if ($order->status === 'confirmed') {
            $order->update(['status' => 'preparing']);
        }

        // Recorded weight may change the line value (weight-priced items).
        $order->calculateTotals();
        $order->reconcilePickingStatus();

        $message = "Picked {$quantity} × {$orderItem->productVariant->name} from batch {$batchItem->batch->batch_code}.";
        if ($weight !== null) {
            $message = "Picked {$quantity} × {$orderItem->productVariant->name} ({$weight} kg) from batch {$batchItem->batch->batch_code}.";
        }

        // Advance to the next unsettled line (the current one again if the pick
        // was partial), or back to the overview — which celebrates when done.
        $next = $order->orderItems->first(fn ($item) => ! $item->isSettled());

        return $next
            ? redirect()->route('picking.item', [$order, $next])->with('success', $message)
            : redirect()->route('picking.show', $order)->with('success', $message);
    }

    /**
     * Undo the line's most recent pick: restores batch stock and leaves the
     * reservation in place (so re-picking the same batch fulfils it again).
     */
    public function undo(Order $order, OrderItem $orderItem): RedirectResponse
    {
        $this->authorize('fulfill', $order);
        abort_if($orderItem->order_id !== $order->id, 404);

        if ($redirect = $this->ensureInQueue($order)) {
            return $redirect;
        }

        $allocation = $orderItem->orderAllocations()
            ->where('quantity_fulfilled', '>', 0)
            ->orderByDesc('id')
            ->first();

        if (! $allocation) {
            return redirect()->route('picking.item', [$order, $orderItem])
                ->withErrors(['undo' => 'Nothing to undo on this line.']);
        }

        $quantity = $allocation->quantity_fulfilled;

        if (! $orderItem->unfulfillAllocation($allocation, $quantity)) {
            return redirect()->route('picking.item', [$order, $orderItem])
                ->withErrors(['undo' => 'Could not undo this pick.']);
        }

        $order->calculateTotals();
        // A no-longer-fully-picked order drops from ready back to preparing.
        $order->reconcilePickingStatus();

        return redirect()->route('picking.item', [$order, $orderItem])
            ->with('success', "Undid pick of {$quantity} — stock restored to batch.");
    }

    /**
     * Close the line short — the outstanding units can't be supplied. Releases
     * the line's reserved-but-unpicked reservation back to stock (picked units
     * are untouched) and settles the line, so an order whose remaining lines
     * can never be filled still leaves preparing for ready. Same narrow
     * fulfill ability as picking itself.
     */
    public function markShort(Request $request, Order $order, OrderItem $orderItem): RedirectResponse
    {
        $this->authorize('fulfill', $order);
        abort_if($orderItem->order_id !== $order->id, 404);

        if ($redirect = $this->ensureInQueue($order)) {
            return $redirect;
        }

        if ($orderItem->isFullyFulfilled()) {
            return redirect()->route('picking.item', [$order, $orderItem])
                ->withErrors(['short' => 'This line is already fully picked — nothing to mark short.']);
        }

        $validated = $request->validate(['short_reason' => 'nullable|string|max:255']);
        $shortQty = $orderItem->quantity_remaining;

        DB::transaction(function () use ($order, $orderItem, $validated) {
            $orderItem->closeShort(($validated['short_reason'] ?? null) ?: 'No stock');
            $order->calculateTotals();
            $order->reconcilePickingStatus();
        });

        $next = $order->fresh()->load('orderItems')->orderItems
            ->first(fn ($item) => ! $item->isSettled());

        $message = "Marked short — {$shortQty} × {$orderItem->productVariant->name} won't be supplied or billed.";

        return $next
            ? redirect()->route('picking.item', [$order, $next])->with('success', $message)
            : redirect()->route('picking.show', $order)->with('success', $message);
    }

    /**
     * Reopen a short-closed line — stock arrived, or it was closed by mistake.
     * The shortfall becomes outstanding work again and the order drops back to
     * preparing via reconcilePickingStatus(). Does not re-reserve stock.
     */
    public function reopenShort(Order $order, OrderItem $orderItem): RedirectResponse
    {
        $this->authorize('fulfill', $order);
        abort_if($orderItem->order_id !== $order->id, 404);

        if ($redirect = $this->ensureInQueue($order)) {
            return $redirect;
        }

        DB::transaction(function () use ($order, $orderItem) {
            $orderItem->reopenShort();
            $order->calculateTotals();
            $order->reconcilePickingStatus();
        });

        return redirect()->route('picking.item', [$order, $orderItem])
            ->with('success', 'Line reopened — it needs picking again.');
    }

    /**
     * Orders outside the picking statuses bounce back to the queue.
     */
    private function ensureInQueue(Order $order): ?RedirectResponse
    {
        if (in_array($order->status, self::QUEUE_STATUSES, true)) {
            return null;
        }

        return redirect()->route('picking.index')
            ->withErrors(['order' => "Order {$order->order_number} is not in the picking queue ({$order->status})."]);
    }

    /**
     * Batch choices for picking a line: the FIFO-sorted available batch items
     * PLUS any batch already holding an unfulfilled reservation for this line
     * (office pre-allocation). Picking fulfils that reservation first, so a
     * pre-allocated batch must stay choosable even when its open stock is
     * fully reserved (available_quantity already excludes our own reservation
     * — see BatchItem::getAvailableQuantityAttribute()).
     *
     * @return Collection<int, array{batchItem: BatchItem, reserved: int, max: int}>
     */
    private function batchOptionsFor(OrderItem $orderItem): Collection
    {
        $reservedByBatchItem = $orderItem->orderAllocations()
            ->whereColumn('quantity_fulfilled', '<', 'quantity_allocated')
            ->get()
            ->groupBy('batch_item_id')
            ->map(fn ($allocations) => (int) $allocations->sum(
                fn ($allocation) => $allocation->quantity_allocated - $allocation->quantity_fulfilled
            ));

        return BatchItem::allocatable()
            ->with('batch')
            ->where('product_variant_id', $orderItem->product_variant_id)
            ->get()
            ->filter(fn ($batchItem) => $reservedByBatchItem->has($batchItem->id) || $batchItem->isAvailableForAllocation())
            ->sortBy(fn ($batchItem) => $batchItem->batch->production_date)
            ->values()
            ->map(function ($batchItem) use ($reservedByBatchItem) {
                $reserved = (int) ($reservedByBatchItem[$batchItem->id] ?? 0);

                return [
                    'batchItem' => $batchItem,
                    'reserved' => $reserved,
                    'max' => $reserved + $batchItem->available_quantity,
                ];
            });
    }
}
