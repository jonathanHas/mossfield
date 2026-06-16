<?php

namespace App\Http\Controllers;

use App\Models\BatchItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    /** Orders that belong on the picking queue. */
    private const QUEUE_STATUSES = ['confirmed', 'preparing', 'ready'];

    /**
     * Today's queue — every order awaiting or in picking, plus freshly
     * readied ones, sorted by delivery date (undated last).
     */
    public function index(): View
    {
        $this->authorize('viewAny', Order::class);

        $orders = Order::with(['customer', 'orderItems'])
            ->whereIn('status', self::QUEUE_STATUSES)
            ->orderByRaw('delivery_date is null')
            ->orderBy('delivery_date')
            ->orderBy('created_at')
            ->get();

        $pickedUnits = (int) $orders->sum(fn ($order) => $order->orderItems->sum('quantity_fulfilled'));
        $totalUnits = (int) $orders->sum(fn ($order) => $order->orderItems->sum('quantity_ordered'));

        return view('picking.index', compact('orders', 'pickedUnits', 'totalUnits'));
    }

    /**
     * Order overview — line-by-line picking state. Renders the "order ready"
     * celebration variant once every line is fulfilled.
     */
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

        $nextItem = $order->orderItems->first(fn ($item) => ! $item->isFullyFulfilled());

        // FIFO batch suggestion for the "next up" hint.
        $suggestedBatch = $nextItem
            ? $this->batchOptionsFor($nextItem)->first(fn ($option) => $option['max'] > 0)
            : null;

        // The order after this one in the queue, for the ready screen's "next up" card.
        $nextOrder = null;
        if ($order->isFullyFulfilled()) {
            $nextOrder = Order::with('orderItems')
                ->whereIn('status', ['confirmed', 'preparing'])
                ->whereKeyNot($order->id)
                ->orderByRaw('delivery_date is null')
                ->orderBy('delivery_date')
                ->orderBy('created_at')
                ->first();
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
        $skipTo = $order->orderItems
            ->first(fn ($item) => ! $item->isFullyFulfilled() && $item->id !== $orderItem->id);

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

        $rules = [
            'batch_item_id' => 'required|exists:batch_items,id',
            'quantity' => 'required|integer|min:1|max:'.$orderItem->quantity_remaining,
        ];

        if ($orderItem->isVariableWeight()) {
            $rules['actual_weight_kg'] = 'required|numeric|min:0.001';
        }

        $validated = $request->validate($rules);

        $batchItem = BatchItem::with('batch')->findOrFail($validated['batch_item_id']);

        if ($batchItem->product_variant_id !== $orderItem->product_variant_id) {
            return redirect()->route('picking.item', [$order, $orderItem])
                ->withErrors(['batch_item_id' => 'Selected batch does not match this product.']);
        }

        $quantity = (int) $validated['quantity'];
        $weight = isset($validated['actual_weight_kg']) ? (float) $validated['actual_weight_kg'] : null;

        if (! $orderItem->pickFromBatchItem($batchItem, $quantity, $weight)) {
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

        // Advance to the next unpicked line (the current one again if the pick
        // was partial), or back to the overview — which celebrates when done.
        $next = $order->orderItems->first(fn ($item) => ! $item->isFullyFulfilled());

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

        return BatchItem::with('batch')
            ->where('product_variant_id', $orderItem->product_variant_id)
            ->where('quantity_remaining', '>', 0)
            ->whereHas('batch', function ($q) {
                $q->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('expiry_date')
                            ->orWhere('expiry_date', '>', now()->toDateString());
                    });
            })
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
