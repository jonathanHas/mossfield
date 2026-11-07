<?php

// Demo orders for the user-guide screenshots (docs/guide/screenshots.json).
//
//   php artisan tinker docs/guide/demo-data.php                 # make what is missing
//   GUIDE_FRESH=1 php artisan tinker docs/guide/demo-data.php   # cancel the old ones, make new
//
// Dev box only (APP_ENV=local, database mossfield_cl). Idempotent: each order
// carries a customer reference GUIDE-A … GUIDE-D, and a non-cancelled order
// with that reference is left alone. It never deletes an order. Prints one
// line per order.
//
// GUIDE_FRESH=1 (tinker takes no arguments, hence an environment variable)
// first cancels every open GUIDE-* order the way the app does — each line's
// reserved and picked units go back to stock through OrderItem::releaseUnits(),
// then the status is written — so the normal path below finds nothing and
// makes four new orders for the coming run dates. Use it when the delivery
// dates have gone by.
//
// Before the orders it makes sure there is sellable milk: when free 1L or 2L
// stock is below what the four orders need, it records a fresh milk batch the
// way BatchController::store() does (tagged in its notes) and prints
// "made batch <code> …". Milk expires in ten days; this keeps the fresh path
// working after it does.
//
// Either way it ends by writing docs/guide/demo-data.json (gitignored — the
// ids are this box's): each order's id, number, status, delivery date and
// line ids by variant name. screenshot.mjs fills the manifest's
// {GUIDE-A.id} / {GUIDE-A.date} / {GUIDE-A.item.1L Bottle} placeholders from it.

use App\Models\Batch;
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

if (filter_var(getenv('GUIDE_FRESH'), FILTER_VALIDATE_BOOLEAN)) {
    $guideOpen = Order::where('customer_reference', 'like', 'GUIDE-%')
        ->where('status', '!=', 'cancelled')
        ->orderBy('id')
        ->get();

    foreach ($guideOpen as $order) {
        if (! $order->canBeCancelled()) {
            echo "! {$order->customer_reference}  id={$order->id}  {$order->order_number}  is {$order->status} and cannot be cancelled; left as it is\n";

            continue;
        }

        // What OrderController::update() does on → cancelled.
        DB::transaction(function () use ($order) {
            foreach ($order->orderItems()->get() as $item) {
                $item->releaseUnits($item->quantity_allocated);
            }
            $order->update(['status' => 'cancelled']);
            $order->calculateTotals();
        });

        echo "cancelled  {$order->customer_reference}  id={$order->id}  {$order->order_number}\n";
    }
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

// Sellable milk for the orders below (they need 56 × 1L and 20 × 2L).
$guideEnsureMilk = function () use ($guideVariant): void {
    $oneLitre = $guideVariant('Milk', '1L Bottle');
    $twoLitre = $guideVariant('Milk', '2L Bottle');
    $free = BatchItem::freeStockByVariant([$oneLitre->id, $twoLitre->id]);

    if (($free[$oneLitre->id] ?? 0) >= 60 && ($free[$twoLitre->id] ?? 0) >= 20) {
        return;
    }

    $product = $oneLitre->product;
    $batch = DB::transaction(function () use ($product, $oneLitre, $twoLitre) {
        $batch = Batch::create([
            'product_id' => $product->id,
            'production_date' => today(),
            'expiry_date' => today()->addDays($product->shelf_life_days ?: 10),
            'raw_milk_litres' => 1200,
            'wheels_produced' => null,
            'notes' => 'Guide demo batch — made by docs/guide/demo-data.php',
        ]);
        foreach ([[$oneLitre, 600], [$twoLitre, 200]] as [$variant, $qty]) {
            BatchItem::create([
                'batch_id' => $batch->id,
                'product_variant_id' => $variant->id,
                'quantity_produced' => $qty,
                'quantity_remaining' => $qty,
                'unit_weight_kg' => $variant->weight_kg,
            ]);
        }

        return $batch;
    });

    echo "made batch {$batch->batch_code} (1L 600, 2L 200)\n";
};
$guideEnsureMilk();

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

$guideResult = [];

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

    $lines = $order->orderItems()->with('productVariant')->get();
    $items = $lines->map(fn ($i) => "{$i->productVariant->name}={$i->id}")->implode(', ');
    $date = optional($order->delivery_date)->toDateString();

    printf("%s  id=%d  %s  %s  %s  [%s]\n", $ref, $order->id, $order->order_number, $order->status, $date ?? '-', $items);

    $guideResult[$ref] = [
        'id' => $order->id,
        'number' => $order->order_number,
        'status' => $order->status,
        'date' => $date,
        'items' => $lines->mapWithKeys(fn ($i) => [$i->productVariant->name => $i->id])->all(),
    ];
}

$guideJsonPath = base_path('docs/guide/demo-data.json');
file_put_contents($guideJsonPath, json_encode([
    'generated' => now()->toIso8601String(),
    'orders' => $guideResult,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

echo "Written $guideJsonPath\n";
