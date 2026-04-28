<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Product;
use Illuminate\Http\Request;

trait BuildsProductList
{
    private function buildProductList(Request $request, Product $selected): array
    {
        // TODO when filters added to /products: pull keys via $request->only([...]).
        $listFilters = [];

        $listLimit = 50;
        $typeOrder = ['milk', 'yoghurt', 'cheese'];

        $query = Product::with(['variants' => function ($q) {
            $q->withSum('batchItems as total_stock', 'quantity_remaining');
        }])->orderBy('name');

        $listTotal = (clone $query)->count();

        $list = $query->limit($listLimit)->get();

        if (! $list->contains('id', $selected->id)) {
            $list->prepend($selected);
        }

        $list = $list->sortBy([
            fn ($a, $b) => array_search($a->type, $typeOrder) <=> array_search($b->type, $typeOrder),
            fn ($a, $b) => strcasecmp($a->name, $b->name),
        ])->values();

        return [$list, $listFilters, $listTotal, $listLimit];
    }
}
