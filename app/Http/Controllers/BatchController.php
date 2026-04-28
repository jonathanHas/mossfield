<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Product;
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
            'batchItems' => fn ($q) => $q->with('productVariant')
                ->withCount('sourceCuttingLogs')
                ->withSum([
                    'orderAllocations as quantity_currently_allocated' => fn ($qa) => $qa->whereNull('fulfilled_at'),
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
            $query->where('status', $request->status);
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
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'production_date' => 'required|date',
            'expiry_date' => 'nullable|date|after:production_date',
            'raw_milk_litres' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string',
            'batch_items' => 'required|array|min:1',
            'batch_items.*.variant_id' => 'required|exists:product_variants,id',
            'batch_items.*.quantity_produced' => 'required|integer|min:1',
            'batch_items.*.unit_weight_kg' => 'nullable|numeric|min:0.001',
        ], [
            'raw_milk_litres.required' => 'Raw milk quantity is required.',
            'raw_milk_litres.min' => 'Raw milk quantity must be greater than 0.',
            'batch_items.required' => 'You must specify production quantities for at least one product variant.',
            'batch_items.min' => 'You must specify production quantities for at least one product variant.',
            'batch_items.*.quantity_produced.required' => 'Quantity produced is required for all variants.',
            'batch_items.*.quantity_produced.min' => 'Quantity produced must be at least 1.',
            'batch_items.*.variant_id.required' => 'Product variant selection is required.',
        ]);

        $batch = DB::transaction(function () use ($validated) {
            $product = Product::find($validated['product_id']);

            // For cheese, the form only collects wheel variants during initial production
            // (vacuum packs are created later via cutting), so the total quantity equals
            // the wheel count. See resources/views/batches/create.blade.php.
            $wheelsProduced = $product->type === 'cheese'
                ? array_sum(array_column($validated['batch_items'], 'quantity_produced'))
                : null;

            $batch = Batch::create([
                'product_id' => $validated['product_id'],
                'production_date' => $validated['production_date'],
                'expiry_date' => $validated['expiry_date'],
                'raw_milk_litres' => $validated['raw_milk_litres'],
                'wheels_produced' => $wheelsProduced,
                'notes' => $validated['notes'],
            ]);

            // Create batch items
            foreach ($validated['batch_items'] as $itemData) {
                BatchItem::create([
                    'batch_id' => $batch->id,
                    'product_variant_id' => $itemData['variant_id'],
                    'quantity_produced' => $itemData['quantity_produced'],
                    'quantity_remaining' => $itemData['quantity_produced'], // Initially all stock remains
                    'unit_weight_kg' => $itemData['unit_weight_kg'],
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
        $batch->load(['product', 'batchItems.productVariant', 'cuttingLogs']);

        return view('batches.show', compact('batch'));
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

    public function destroy(Batch $batch): RedirectResponse
    {
        $this->authorize('delete', $batch);
        $batch->delete();

        return redirect()->route('batches.index')
            ->with('success', 'Batch deleted successfully.');
    }
}
