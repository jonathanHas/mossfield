<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class BatchController extends Controller
{
    public function index(Request $request): View
    {
        $query = Batch::with(['product', 'batchItems.productVariant']);

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
        $products = Product::with('variants')->where('is_active', true)->get();
        return view('batches.create', compact('products'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'production_date' => 'required|date',
            'expiry_date' => 'nullable|date|after:production_date',
            'raw_milk_litres' => 'required|numeric|min:0.001',
            'wheels_produced' => 'nullable|integer|min:1',
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

        $batch = DB::transaction(function () use ($validated, $request) {
            // Get the product to determine if it's cheese
            $product = Product::find($validated['product_id']);
            
            // For cheese, validate that wheels_produced is provided
            if ($product->type === 'cheese' && !$validated['wheels_produced']) {
                throw new \Illuminate\Validation\ValidationException(
                    validator($request->all(), []),
                    ['wheels_produced' => 'Number of wheels produced is required for cheese products.']
                );
            }

            // Create the batch
            $batch = Batch::create([
                'product_id' => $validated['product_id'],
                'production_date' => $validated['production_date'],
                'expiry_date' => $validated['expiry_date'],
                'raw_milk_litres' => $validated['raw_milk_litres'],
                'wheels_produced' => $validated['wheels_produced'] ?? null,
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
            ->with('success', 'Batch ' . $batch->batch_code . ' created successfully! ' . 
                   ($batch->wheels_produced ? $batch->wheels_produced . ' wheels produced.' : ''));
    }

    public function show(Batch $batch): View
    {
        $batch->load(['product', 'batchItems.productVariant', 'cuttingLogs']);
        return view('batches.show', compact('batch'));
    }

    public function edit(Batch $batch): View
    {
        $batch->load(['batchItems.productVariant']);
        $products = Product::with('variants')->where('is_active', true)->get();
        return view('batches.edit', compact('batch', 'products'));
    }

    public function update(Request $request, Batch $batch): RedirectResponse
    {
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
        $batch->delete();

        return redirect()->route('batches.index')
            ->with('success', 'Batch deleted successfully.');
    }
}
