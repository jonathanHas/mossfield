<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\CheeseConversionLog;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CheeseConversionController extends Controller
{
    /**
     * The name of the destination Mature product (seeded by ProductSeeder).
     */
    private const MATURE_PRODUCT_NAME = 'Mossfield Mature Cheese';

    public function index(): View
    {
        $months = (int) config('mossfield.mature_conversion_months', 5);
        $matureProductId = $this->matureProduct()?->id;

        // Active, non-mature cheese batches that have whole wheels — at ANY age.
        // Wheels can be set aside (held) to mature long before they're eligible
        // to actually become the Mature product.
        $readyBatches = Batch::with([
            'product',
            'batchItems' => fn ($q) => $q->with('productVariant')
                ->withSum([
                    'orderAllocations as quantity_currently_allocated' => fn ($qa) => $qa->whereNull('fulfilled_at'),
                ], DB::raw('quantity_allocated - quantity_fulfilled')),
        ])
            ->whereHas('product', fn ($q) => $q->where('type', 'cheese'))
            ->when($matureProductId, fn ($q) => $q->where('product_id', '!=', $matureProductId))
            ->where('status', 'active')
            ->whereHas('batchItems', function ($q) {
                // Has wheel stock to set aside, OR already-held wheels to manage.
                $q->where(fn ($w) => $w->where('quantity_remaining', '>', 0)->orWhere('quantity_maturing', '>', 0))
                    ->whereHas('productVariant', fn ($pv) => $pv->where('name', 'like', '%wheel%'));
            })
            ->orderBy('production_date', 'asc')
            ->get();

        return view('cheese-conversion.index', compact('readyBatches', 'months'));
    }

    /**
     * Set (or change) the maturing hold on a wheel item — a reversible reservation
     * that takes the wheels out of order allocation. Allowed at any age.
     */
    public function hold(Request $request, BatchItem $batchItem): RedirectResponse
    {
        $this->authorize('create', CheeseConversionLog::class);
        $this->guardSourceWheel($batchItem);

        $holdable = $batchItem->holdable_quantity;

        $validated = $request->validate([
            'quantity' => "required|integer|min:0|max:{$holdable}",
        ]);

        $batchItem->update(['quantity_maturing' => (int) $validated['quantity']]);

        return redirect()->route('cheese-conversion.index')
            ->with('success', "Maturing hold updated — {$validated['quantity']} wheel(s) set aside.");
    }

    /**
     * Release held wheels into the Mature product. Only once the batch is old
     * enough; consumes from the maturing hold (not from free stock).
     */
    public function release(BatchItem $batchItem): RedirectResponse
    {
        $this->authorize('create', CheeseConversionLog::class);
        $this->guardSourceWheel($batchItem);

        abort_unless($batchItem->batch->isEligibleForMaturation(), 404, 'This batch has not reached the maturation age yet.');

        // Release the full saved hold — all held wheels in a batch are the same
        // age, so they become eligible together.
        $wheels = (int) $batchItem->quantity_maturing;
        if ($wheels < 1) {
            return back()->withErrors(['error' => 'No wheels are being held to release.']);
        }

        $matureProduct = $this->matureProduct();
        $matureWheelVariant = $matureProduct ? $this->matureWheelVariant($matureProduct) : null;
        if (! $matureWheelVariant) {
            return back()->withErrors(['error' => 'Mature cheese product is not configured. Run the product seeder.']);
        }

        DB::transaction(function () use ($batchItem, $wheels, $matureProduct, $matureWheelVariant) {
            $perWheel = $batchItem->unit_weight_kg;

            // One Mature batch per source ⇒ stable batch_code + 1:1 traceability.
            // batch_code + ready_date are auto-set by Batch::boot().
            $matureBatch = Batch::firstOrCreate(
                [
                    'product_id' => $matureProduct->id,
                    'source_batch_id' => $batchItem->batch_id,
                ],
                [
                    'production_date' => $batchItem->batch->production_date,
                    'raw_milk_litres' => 0, // converted from wheels, not milk-derived
                    'wheels_produced' => 0,
                    'status' => 'active',
                ]
            );

            $matureWheelItem = BatchItem::firstOrCreate(
                [
                    'batch_id' => $matureBatch->id,
                    'product_variant_id' => $matureWheelVariant->id,
                ],
                [
                    'quantity_produced' => 0,
                    'quantity_remaining' => 0,
                    'unit_weight_kg' => $perWheel,
                ]
            );

            // Mint mature wheels; the held farmhouse wheels physically leave the
            // batch (remaining) and the hold (maturing) together.
            $matureWheelItem->increment('quantity_produced', $wheels);
            $matureWheelItem->increment('quantity_remaining', $wheels);
            $matureBatch->increment('wheels_produced', $wheels);
            $batchItem->decrement('quantity_remaining', $wheels);
            $batchItem->decrement('quantity_maturing', $wheels);

            CheeseConversionLog::create([
                'source_batch_item_id' => $batchItem->id,
                'target_batch_item_id' => $matureWheelItem->id,
                'conversion_date' => now()->toDateString(),
                'wheels_converted' => $wheels,
                'total_weight_kg' => round(((float) $perWheel) * $wheels, 3),
            ]);
        });

        return redirect()->route('cheese-conversion.index')
            ->with('success', "Released {$wheels} wheel(s) to mature cheese.");
    }

    /**
     * Reverse a release while the mature wheels are still intact — returns them
     * to the source batch's maturing hold.
     */
    public function undoRelease(CheeseConversionLog $log): RedirectResponse
    {
        $this->authorize('delete', $log);

        $log->loadMissing('targetBatchItem.batch', 'sourceBatchItem');
        $matureItem = $log->targetBatchItem;
        $source = $log->sourceBatchItem;
        $wheels = (int) $log->wheels_converted;

        abort_if(! $matureItem || ! $source, 404);

        // Only reversible while the mature wheels are still present and unreserved
        // (not cut, sold, or allocated to an order).
        if ($matureItem->available_quantity < $wheels) {
            return back()->withErrors(['error' => 'These mature wheels have been cut, sold or allocated — release can no longer be undone.']);
        }

        DB::transaction(function () use ($log, $matureItem, $source, $wheels) {
            $matureItem->decrement('quantity_produced', $wheels);
            $matureItem->decrement('quantity_remaining', $wheels);
            $matureItem->batch->decrement('wheels_produced', $wheels);

            // Back into the source batch, returned to the maturing hold.
            $source->increment('quantity_remaining', $wheels);
            $source->increment('quantity_maturing', $wheels);

            $log->delete();
        });

        return back()->with('success', "Returned {$wheels} wheel(s) to the farmhouse maturing hold.");
    }

    /**
     * Guard that the batch item is a non-mature cheese wheel. (No age gate — the
     * hold is allowed at any age; release adds its own eligibility check.)
     */
    private function guardSourceWheel(BatchItem $batchItem): void
    {
        $batchItem->loadMissing('batch.product', 'productVariant');

        abort_if(
            $batchItem->batch->product->type !== 'cheese'
                || ! str_contains(strtolower($batchItem->productVariant->name), 'wheel'),
            404,
            'Only cheese wheels can be set aside for maturing.'
        );

        abort_if(
            $batchItem->batch->product->name === self::MATURE_PRODUCT_NAME,
            404,
            'This batch is already mature cheese.'
        );
    }

    private function matureProduct(): ?Product
    {
        return Product::where('name', self::MATURE_PRODUCT_NAME)
            ->where('type', 'cheese')
            ->first();
    }

    private function matureWheelVariant(Product $matureProduct): ?ProductVariant
    {
        return ProductVariant::where('product_id', $matureProduct->id)
            ->where('name', 'like', '%wheel%')
            ->where('is_active', true)
            ->first();
    }
}
