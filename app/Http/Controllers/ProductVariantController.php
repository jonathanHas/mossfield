<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsProductList;
use App\Http\Requests\ProductVariantRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProductVariantController extends Controller
{
    use BuildsProductList;

    /**
     * Show the form for creating a new variant for a product.
     */
    public function create(Request $request, Product $product): View
    {
        $source = null;

        if ($request->filled('from')) {
            $source = $product->variants()->find($request->integer('from'));
        }

        [$productList, $listFilters, $listTotal, $listLimit] = $this->buildProductList($request, $product);

        return view('products.variants.create', compact('product', 'source', 'productList', 'listFilters', 'listTotal', 'listLimit'));
    }

    /**
     * Store a newly created variant in storage.
     */
    public function store(ProductVariantRequest $request, Product $product): RedirectResponse
    {
        $data = $request->safe()->except(['image', 'remove_image']);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('product-variants', 'public');
        }

        $product->variants()->create($data);

        return redirect()->route('products.show', $product)
            ->with('success', 'Product variant created successfully.');
    }

    /**
     * Show the form for editing the specified variant.
     */
    public function edit(Request $request, Product $product, ProductVariant $variant): View
    {
        [$productList, $listFilters, $listTotal, $listLimit] = $this->buildProductList($request, $product);

        return view('products.variants.edit', compact('variant', 'product', 'productList', 'listFilters', 'listTotal', 'listLimit'));
    }

    /**
     * Update the specified variant in storage.
     */
    public function update(ProductVariantRequest $request, Product $product, ProductVariant $variant): RedirectResponse
    {
        $data = $request->safe()->except(['image', 'remove_image']);

        if ($request->hasFile('image')) {
            if ($variant->image_path) {
                Storage::disk('public')->delete($variant->image_path);
            }
            $data['image_path'] = $request->file('image')->store('product-variants', 'public');
        } elseif ($request->boolean('remove_image') && $variant->image_path) {
            Storage::disk('public')->delete($variant->image_path);
            $data['image_path'] = null;
        }

        $variant->update($data);

        return redirect()->route('products.show', $product)
            ->with('success', 'Product variant updated successfully.');
    }

    /**
     * Remove the specified variant from storage.
     */
    public function destroy(Product $product, ProductVariant $variant): RedirectResponse
    {
        if ($variant->image_path) {
            Storage::disk('public')->delete($variant->image_path);
        }

        $variant->delete();

        return redirect()->route('products.show', $product)
            ->with('success', 'Product variant deleted successfully.');
    }
}
