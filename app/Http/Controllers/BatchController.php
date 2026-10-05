<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BatchController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Batch::class);
        $query = Batch::with([
            'product',
            'sourceBatch.product',
            'matureBatches.product',
            // The *_count columns also feed Batch::deletionBlocker(), so the
            // card can hide Delete without a query per batch.
            'batchItems' => fn ($q) => $q->with('productVariant')
                ->withCount([
                    'sourceCuttingLogs',
                    'orderAllocations',
                    'targetCuttingLogs',
                    'sourceConversionLogs',
                    'targetConversionLogs',
                    'writeOffs',
                ])
                ->withSum('sourceConversionLogs as converted_wheels', 'wheels_converted')
                // Active write-offs only — a reversed write-off isn't one
                // (a legitimate filter, unlike fulfilled_at on reservations).
                ->withSum(['writeOffs as written_off_units' => fn ($q) => $q->whereNull('reversed_at')], 'quantity')
                ->withSum([
                    'orderAllocations as quantity_currently_allocated',
                ], DB::raw('quantity_allocated - quantity_fulfilled')),
        ]);

        // Filter by product type
        if ($request->filled('type')) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('type', $request->type);
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            if ($request->status === 'expired') {
                // "Expired" means unsellable: closed as expired, or still
                // marked active with its expiry passed (strict, as
                // Batch::isExpired()) — the dashboard tile links here.
                $query->where(function ($q) {
                    $q->where('status', 'expired')
                        ->orWhere(fn ($q) => $q->where('status', 'active')->where('expiry_date', '<=', today()));
                });
            } else {
                $query->where('status', $request->status);
            }
        }

        // Filter by maturation status for cheese
        if ($request->filled('maturation_status')) {
            if ($request->maturation_status === 'ready') {
                $query->where(function ($q) {
                    $q->whereNull('ready_date')
                        ->orWhere('ready_date', '<=', now());
                });
            } elseif ($request->maturation_status === 'maturing') {
                $query->where('ready_date', '>', now());
            }
        }

        $batches = $query->orderBy('production_date', 'desc')->paginate(20);

        return view('batches.index', compact('batches'));
    }

    public function create(): View
    {
        $this->authorize('create', Batch::class);
        $products = Product::with('variants')->where('is_active', true)->get();

        return view('batches.create', compact('products'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Batch::class);

        // Rows left blank (or 0) mean "this variant wasn't produced in this batch" — drop
        // them before validation so only entered variants are required to be valid.
        $request->merge([
            'batch_items' => collect($request->input('batch_items', []))
                ->filter(fn ($item) => ($item['quantity_produced'] ?? '') !== ''
                    && (float) $item['quantity_produced'] != 0)
                ->values()
                ->all(),
        ]);

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'production_date' => 'required|date',
            'expiry_date' => 'nullable|date|after:production_date',
            'raw_milk_litres' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string',
            'batch_items' => 'required|array|min:1',
            'batch_items.*.variant_id' => 'required|exists:product_variants,id',
            'batch_items.*.quantity_produced' => 'required|integer|min:1',
        ], [
            'raw_milk_litres.required' => 'Raw milk quantity is required.',
            'raw_milk_litres.min' => 'Raw milk quantity must be greater than 0.',
            'batch_items.required' => 'You must specify production quantities for at least one product variant.',
            'batch_items.min' => 'You must specify production quantities for at least one product variant.',
            'batch_items.*.quantity_produced.integer' => 'Quantity produced must be a whole number.',
            'batch_items.*.quantity_produced.min' => 'Quantity produced must be at least 1.',
            'batch_items.*.variant_id.required' => 'Product variant selection is required.',
        ]);

        $batch = DB::transaction(function () use ($validated) {
            $product = Product::find($validated['product_id']);

            // Unit weight is a property of the variant, not something set per batch — source it
            // from the variant definition so operators can't override it on the create form.
            $variantWeights = ProductVariant::whereIn('id', array_column($validated['batch_items'], 'variant_id'))
                ->pluck('weight_kg', 'id');

            // For cheese, the form only collects wheel variants during initial production
            // (vacuum packs are created later via cutting), so the total quantity equals
            // the wheel count. See resources/views/batches/create.blade.php.
            $wheelsProduced = $product->type === 'cheese'
                ? array_sum(array_column($validated['batch_items'], 'quantity_produced'))
                : null;

            $batch = Batch::create([
                'product_id' => $validated['product_id'],
                'production_date' => $validated['production_date'],
                'expiry_date' => $validated['expiry_date'] ?? null,
                'raw_milk_litres' => $validated['raw_milk_litres'],
                'wheels_produced' => $wheelsProduced,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Create batch items
            foreach ($validated['batch_items'] as $itemData) {
                BatchItem::create([
                    'batch_id' => $batch->id,
                    'product_variant_id' => $itemData['variant_id'],
                    'quantity_produced' => $itemData['quantity_produced'],
                    'quantity_remaining' => $itemData['quantity_produced'], // Initially all stock remains
                    'unit_weight_kg' => $variantWeights[$itemData['variant_id']] ?? null,
                ]);
            }

            return $batch;
        });

        return redirect()->route('batches.show', $batch)
            ->with('success', 'Batch '.$batch->batch_code.' created successfully! '.
                   ($batch->wheels_produced ? $batch->wheels_produced.' wheels produced.' : ''));
    }

    public function show(Batch $batch): View
    {
        $this->authorize('view', $batch);
        $batch->load([
            'product', 'batchItems.productVariant', 'cuttingLogs.sourceBatchItem.productVariant', 'sourceBatch.product', 'matureBatches.product',
            'batchItems.targetConversionLogs.sourceBatchItem.batch.product',
            // Everything BatchItem::weight_breakdown reads, loaded once.
            'batchItems.orderAllocations', 'batchItems.sourceCuttingLogs', 'batchItems.sourceConversionLogs', 'batchItems.targetCuttingLogs',
            'batchItems.writeOffs.reason', 'batchItems.writeOffs.writtenOffBy', 'batchItems.writeOffs.reversedBy',
        ]);

        // The write-off form's reasons (active only — a deactivated reason
        // can't be chosen) and the history, newest first.
        $writeOffReasons = auth()->user()->can('create', \App\Models\StockWriteOff::class)
            ? \App\Models\WriteOffReason::active()->ordered()->get()
            : collect();
        $writeOffs = $batch->batchItems
            ->flatMap(fn ($item) => $item->writeOffs->each(fn ($w) => $w->setRelation('batchItem', $item)))
            ->sortByDesc(fn ($w) => [$w->written_off_on?->toDateString(), $w->id])
            ->values();

        return view('batches.show', compact('batch', 'writeOffReasons', 'writeOffs'));
    }

    public function edit(Batch $batch): View
    {
        $this->authorize('update', $batch);
        $batch->load(['batchItems.productVariant']);
        $products = Product::with('variants')->where('is_active', true)->get();

        return view('batches.edit', compact('batch', 'products'));
    }

    public function update(Request $request, Batch $batch): RedirectResponse
    {
        $this->authorize('update', $batch);
        $validated = $request->validate([
            'expiry_date' => 'nullable|date|after:production_date',
            'notes' => 'nullable|string',
            'status' => 'required|in:active,sold_out,expired',
        ]);

        $batch->update($validated);

        return redirect()->route('batches.show', $batch)
            ->with('success', 'Batch updated successfully.');
    }

    /**
     * Delete a batch — only while nothing depends on it.
     *
     * `batch_items.batch_id` cascades, and so does every child of a batch item
     * (`order_allocations`, `cheese_cutting_logs`, `cheese_conversion_logs`, on
     * both their source and target sides). Without this guard, deleting a batch
     * an order had been picked from silently removed the allocations underneath
     * it: `order_items.quantity_fulfilled` survived, but the record of where
     * those units came from did not — so a delivered, invoiced order lost its
     * batch traceability. Closing a batch (status `sold_out` / `expired`) is
     * the way to take it out of circulation; deletion is for mistakes only.
     */
    public function destroy(Batch $batch): RedirectResponse
    {
        $this->authorize('delete', $batch);

        if ($blocker = $batch->deletionBlocker()) {
            return redirect()->route('batches.show', $batch)
                ->with('error', $blocker);
        }

        $batch->delete();

        return redirect()->route('batches.index')
            ->with('success', 'Batch deleted successfully.');
    }
}
