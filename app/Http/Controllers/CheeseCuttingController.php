<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\CheeseCuttingLog;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class CheeseCuttingController extends Controller
{
    public function index(): View
    {
        // Get all cheese batches that are ready to cut with available whole wheels
        $readyBatches = Batch::with(['product', 'batchItems.productVariant'])
            ->whereHas('product', function ($q) {
                $q->where('type', 'cheese');
            })
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('ready_date')
                    ->orWhere('ready_date', '<=', now());
            })
            ->whereHas('batchItems', function ($q) {
                $q->where('quantity_remaining', '>', 0)
                    ->whereHas('productVariant', function ($pv) {
                        $pv->where('name', 'like', '%wheel%');
                    });
            })
            ->orderBy('production_date', 'asc')
            ->get();

        return view('cheese-cutting.index', compact('readyBatches'));
    }

    public function create(BatchItem $batchItem): View
    {
        // Ensure this is a wheel from a cheese batch
        if ($batchItem->batch->product->type !== 'cheese' || 
            !str_contains(strtolower($batchItem->productVariant->name), 'wheel')) {
            abort(404, 'Only cheese wheels can be cut.');
        }

        // Get vacuum pack variants for this product
        $vacuumPackVariants = ProductVariant::where('product_id', $batchItem->batch->product_id)
            ->where('name', 'like', '%vacuum%')
            ->where('is_active', true)
            ->get();

        return view('cheese-cutting.create', compact('batchItem', 'vacuumPackVariants'));
    }

    public function store(Request $request, BatchItem $batchItem): RedirectResponse
    {
        $validated = $request->validate([
            'cut_date' => 'required|date|before_or_equal:today',
            'vacuum_pack_variant_id' => 'required|exists:product_variants,id',
            'vacuum_packs_created' => 'required|integer|min:1',
            'total_weight_kg' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Ensure we have enough wheels to cut
        if ($batchItem->quantity_remaining < 1) {
            return back()->withErrors(['error' => 'No wheels remaining to cut.']);
        }

        DB::transaction(function () use ($validated, $batchItem) {
            // Get or create batch item for vacuum packs in the same batch
            $vacuumPackBatchItem = BatchItem::firstOrCreate(
                [
                    'batch_id' => $batchItem->batch_id,
                    'product_variant_id' => $validated['vacuum_pack_variant_id'],
                ],
                [
                    'quantity_produced' => 0,
                    'quantity_remaining' => 0,
                    'unit_weight_kg' => ProductVariant::find($validated['vacuum_pack_variant_id'])->weight_kg,
                ]
            );

            // Update quantities
            $vacuumPackBatchItem->increment('quantity_produced', $validated['vacuum_packs_created']);
            $vacuumPackBatchItem->increment('quantity_remaining', $validated['vacuum_packs_created']);
            
            // Reduce wheel count by 1
            $batchItem->decrement('quantity_remaining', 1);

            // Create cutting log
            CheeseCuttingLog::create([
                'source_batch_item_id' => $batchItem->id,
                'target_batch_item_id' => $vacuumPackBatchItem->id,
                'cut_date' => $validated['cut_date'],
                'vacuum_packs_created' => $validated['vacuum_packs_created'],
                'total_weight_kg' => $validated['total_weight_kg'],
                'notes' => $validated['notes'],
            ]);
        });

        return redirect()->route('cheese-cutting.index')
            ->with('success', 'Cheese wheel cut successfully! Created ' . $validated['vacuum_packs_created'] . ' vacuum packs.');
    }
}
