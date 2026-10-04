<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

trait BuildsProductList
{
    private function buildProductList(Request $request, Product $selected): array
    {
        // TODO when filters added to /products: pull keys via $request->only([...]).
        $listFilters = [];

        $listLimit = 50;
        $typeOrder = ['milk', 'yoghurt', 'cheese'];

        $query = Product::with('variants')->orderBy('name');

        $listTotal = (clone $query)->count();

        $list = $query->limit($listLimit)->get();

        if (! $list->contains('id', $selected->id)) {
            $list->prepend($selected);
        }

        // Free stock per variant in one query (see ProductVariant::total_stock).
        ProductVariant::attachFreeStock($list->flatMap->variants);

        $list = $list->sortBy([
            fn ($a, $b) => array_search($a->type, $typeOrder) <=> array_search($b->type, $typeOrder),
            fn ($a, $b) => strcasecmp($a->name, $b->name),
        ])->values();

        return [$list, $listFilters, $listTotal, $listLimit];
    }
}
