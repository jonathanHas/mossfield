<?php

// Demo orders for the user-guide screenshots (docs/guide/screenshots.json).
//
//   php artisan tinker docs/guide/demo-data.php
//
// Dev box only (APP_ENV=local, database mossfield_cl). Idempotent: each order
// carries a customer reference GUIDE-A … GUIDE-D, and a non-cancelled order
// with that reference is left alone. It never deletes anything. Prints one
// line per order — the ids the manifest's URLs use.

use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

if (! app()->environment('local')) {
    throw new RuntimeException('Dev only.');
}
if (config('database.connections.mysql.database') !== 'mossfield_cl') {
    throw new RuntimeException('Dev only: expected the mossfield_cl database.');
}

$guideRunDate = function (int $runId, int $weeksLater = 0): CarbonImmutable {
    $date = DeliveryRun::findOrFail($runId)->dateFor(now());
    if ($date->lt(today())) {
        $date = $date->addWeek();
    }

    return $date->addWeeks($weeksLater);
};

// Variant by product + variant name, so the script doesn't depend on ids.
$guideVariant = fn (string $product, string $variant): ProductVariant => ProductVariant::whereHas(
    'product', fn ($q) => $q->where('name', 'like', "%$product%")
)->where('name', $variant)->firstOrFail();

$guideAllocate = function (Order $order): void {
    foreach ($order->orderItems as $item) {
        $outstanding = $item->quantity_ordered - $item->quantity_allocated;
        $batchItems = BatchItem::allocatable()
            ->with('batch')
            ->where('product_variant_id', $item->product_variant_id)
            ->get()
            ->filter(fn ($bi) => $bi->isAvailableForAllocation())
            ->sortBy('batch.production_date');

        foreach ($batchItems as $bi) {
            if ($outstanding <= 0) {
                break;
            }
            $qty = min($outstanding, $bi->available_quantity);
            if ($qty > 0 && $item->allocateFromBatchItem($bi, $qty)) {
                $outstanding -= $qty;
            }
        }
        if ($outstanding > 0) {
            echo "  ! {$order->customer_reference}: {$outstanding} short on {$item->productVariant->name}\n";
        }
    }
};

$guideOrders = [
    'GUIDE-A' => [
        'customer' => 'Organic Store', 'run' => 2, 'weeks' => 0, 'state' => 'allocated',
        'lines' => [['Milk', '1L Bottle', 20], ['Milk', '2L Bottle', 10], ['Yoghurt', '500g Tub', 12], ['Farmhouse', 'Whole Wheel', 2]],
    ],
    'GUIDE-B' => [
        'customer' => 'Organic Store', 'run' => 2, 'weeks' => 1, 'state' => 'pending',
        'lines' => [['Milk', '1L Bottle', 24], ['Yoghurt', '250g Tub', 6]],
    ],
    'GUIDE-C' => [
        'customer' => 'Cavistons', 'run' => 1, 'weeks' => 0, 'state' => 'preparing',
        'lines' => [['Milk', '1L Bottle', 12], ['Yoghurt', '500g Tub', 6], ['Farmhouse', 'Whole Wheel', 1]],
    ],
    'GUIDE-D' => [
        'customer' => 'Roundwood Stores', 'run' => 1, 'weeks' => 0, 'state' => 'pending',
        'lines' => [['Milk', '2L Bottle', 10], ['Farmhouse', 'Vacuum Pack', 4]],
    ],
];

foreach ($guideOrders as $ref => $spec) {
    $order = Order::where('customer_reference', $ref)->where('status', '!=', 'cancelled')->first();

    if (! $order) {
        $order = DB::transaction(function () use ($ref, $spec, $guideRunDate, $guideVariant) {
            $customer = Customer::where('name', $spec['customer'])->firstOrFail();
            $date = $guideRunDate($spec['run'], $spec['weeks']);

            $order = Order::create([
                'customer_id' => $customer->id,
                'order_date' => today(),
                'delivery_date' => $date->toDateString(),
                'delivery_charge' => $customer->currentDeliveryCharge($date),
                'delivery_charge_percent' => $customer->deliveryChargePercent(),
                'customer_reference' => $ref,
                'status' => $spec['state'] === 'pending' ? 'pending' : 'confirmed',
                'payment_status' => 'pending',
            ]);

            foreach ($spec['lines'] as [$product, $variantName, $qty]) {
                $variant = $guideVariant($product, $variantName);
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $variant->id,
                    'quantity_ordered' => $qty,
                    'unit_price' => $customer->unitPriceFor($variant),
                ]);
            }
            $order->calculateTotals();

            return $order;
        });

        if ($spec['state'] !== 'pending') {
            $guideAllocate($order->load('orderItems.productVariant'));
        }

        if ($spec['state'] === 'preparing') {
            // Pick the milk line in full from the batch it was reserved on.
            $milk = $order->orderItems()->whereHas('productVariant', fn ($q) => $q->where('name', '1L Bottle'))->firstOrFail();
            $allocation = $milk->orderAllocations()->with('batchItem')->firstOrFail();
            if (! $milk->pickFromBatchItem($allocation->batchItem, $milk->quantity_ordered)) {
                throw new RuntimeException("$ref: the milk pick failed.");
            }
            $order->refresh();
            if ($order->status === 'confirmed') {
                $order->update(['status' => 'preparing']);
            }
            $order->reconcilePickingStatus();
            $order->calculateTotals();
        }

        $order->refresh();
    }

    $items = $order->orderItems()->with('productVariant')->get()
        ->map(fn ($i) => "{$i->productVariant->name}={$i->id}")->implode(', ');
    printf("%s  id=%d  %s  %s  %s  [%s]\n", $ref, $order->id, $order->order_number, $order->status,
        optional($order->delivery_date)->toDateString() ?? '-', $items);
}
