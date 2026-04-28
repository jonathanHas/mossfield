<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsProductList;
use App\Http\Requests\ProductRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProductController extends Controller
{
    use BuildsProductList;

    public function index(): View
    {
        $this->authorize('viewAny', Product::class);
        $typeOrder = ['milk', 'yoghurt', 'cheese'];

        $products = Product::with(['variants' => function ($query) {
            $query->withSum('batchItems as total_stock', 'quantity_remaining')
                ->orderBy('name');
        }])
            ->orderBy('name')
            ->get()
            ->groupBy('type')
            ->sortBy(fn ($group, $type) => array_search($type, $typeOrder) === false ? 99 : array_search($type, $typeOrder));

        return view('products.index', compact('products', 'typeOrder'));
    }

    public function create(): View
    {
        $this->authorize('create', Product::class);

        return view('products.create');
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $data = $request->safe()->except(['image', 'remove_image']);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }

        Product::create($data);

        return redirect()->route('products.index')
            ->with('success', 'Product created successfully.');
    }

    public function show(Request $request, Product $product): View
    {
        $this->authorize('view', $product);
        $product->load([
            'variants' => function ($query) {
                $query->withSum('batchItems as total_stock', 'quantity_remaining');
            },
            'batches' => function ($query) {
                $query->orderByDesc('production_date')->limit(10);
            },
            'batches.batchItems',
        ]);

        [$productList, $listFilters, $listTotal, $listLimit] = $this->buildProductList($request, $product);

        return view('products.show', compact('product', 'productList', 'listFilters', 'listTotal', 'listLimit'));
    }

    public function edit(Request $request, Product $product): View
    {
        $this->authorize('update', $product);

        [$productList, $listFilters, $listTotal, $listLimit] = $this->buildProductList($request, $product);

        return view('products.edit', compact('product', 'productList', 'listFilters', 'listTotal', 'listLimit'));
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $data = $request->safe()->except(['image', 'remove_image']);

        if ($request->hasFile('image')) {
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }
            $data['image_path'] = $request->file('image')->store('products', 'public');
        } elseif ($request->boolean('remove_image') && $product->image_path) {
            Storage::disk('public')->delete($product->image_path);
            $data['image_path'] = null;
        }

        $product->update($data);

        return redirect()->route('products.show', $product)
            ->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        $product->delete();

        return redirect()->route('products.index')
            ->with('success', 'Product deleted successfully.');
    }
}
