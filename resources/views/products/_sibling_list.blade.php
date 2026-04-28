@php
    $listFilters = $listFilters ?? [];
    $productList = $productList ?? collect();
    $listTotal = $listTotal ?? $productList->count();
    $listLimit = $listLimit ?? 50;
    $mode = $mode ?? 'show';
    $activeVariant = $activeVariant ?? null;
    $showActiveVariants = $showActiveVariants ?? ($activeVariant !== null);
    $typeOrder = ['milk', 'yoghurt', 'cheese'];
    $grouped = $productList->groupBy('type')->sortBy(fn ($g, $type) => array_search($type, $typeOrder) === false ? 99 : array_search($type, $typeOrder));
@endphp

<div class="px-4 py-3 sticky top-0 z-10" style="background: var(--bg); border-bottom: 1px solid var(--line-2);">
    <div class="flex items-center justify-between gap-2">
        <div class="text-[13px] font-semibold">Products</div>
        <a href="{{ route('products.index', $listFilters) }}" class="mf-link text-[12px]">All products →</a>
    </div>
</div>

<div class="flex-1">
    @foreach($grouped as $type => $group)
        <div class="px-4 pt-3 pb-1.5 text-[11px] uppercase tracking-wide" style="color: var(--muted); border-bottom: 1px solid var(--line-2);">
            {{ ucfirst($type) }} <span style="color: var(--faint);">· {{ $group->count() }}</span>
        </div>
        <ul>
            @foreach($group as $sibling)
                @php
                    $isActive = $sibling->id === $product->id;
                    $variantCount = $sibling->variants->count();
                    $totalStock = $sibling->variants->sum(fn ($v) => (int) ($v->total_stock ?? 0));
                @endphp
                <li>
                    <a href="{{ route('products.'.$mode, array_merge($listFilters, ['product' => $sibling->id])) }}"
                       class="block px-4 py-2.5 transition-colors"
                       style="border-bottom: 1px solid var(--line-2); {{ $isActive ? 'background: var(--accent-soft, #f5f3ee); border-left: 3px solid var(--accent, #4a7c4d); padding-left: 13px;' : '' }}">
                        <div class="flex items-baseline justify-between gap-2">
                            <div class="text-[13px] font-medium truncate">{{ $sibling->name }}</div>
                            @if(! $sibling->is_active)
                                <span class="mf-tag mf-tag-danger flex-shrink-0">Inactive</span>
                            @endif
                        </div>
                        <div class="mt-0.5 text-[11.5px]" style="color: var(--muted);">
                            {{ $variantCount }} {{ \Illuminate\Support\Str::plural('variant', $variantCount) }}
                            @if($variantCount > 0)
                                · {{ $totalStock }} in stock
                            @endif
                        </div>
                    </a>

                    @if($isActive && $showActiveVariants && $variantCount > 0)
                        <ul style="background: var(--bg-soft, #fafaf7); border-bottom: 1px solid var(--line-2);">
                            @foreach($sibling->variants as $v)
                                @php
                                    $isActiveV = $activeVariant && $activeVariant->id === $v->id;
                                @endphp
                                <li>
                                    <a href="{{ route('products.variants.edit', [$sibling->id, $v->id]) }}"
                                       class="block pl-7 pr-4 py-1.5 text-[12px] flex items-center justify-between gap-2 transition-colors"
                                       style="{{ $isActiveV ? 'background: var(--accent-soft, #f5f3ee); border-left: 3px solid var(--accent, #4a7c4d); padding-left: 25px;' : 'border-left: 3px solid transparent;' }}">
                                        <span class="truncate">{{ $v->name }}</span>
                                        @if(! $v->is_active)
                                            <span class="mf-tag mf-tag-danger flex-shrink-0" style="font-size: 10px;">Off</span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    @endforeach
</div>

@if($listTotal > $listLimit)
    <div class="px-4 py-3 text-[12px]" style="color: var(--muted); border-top: 1px solid var(--line-2);">
        Showing {{ $listLimit }} of {{ $listTotal }} ·
        <a href="{{ route('products.index', $listFilters) }}" class="mf-link">view all</a>
    </div>
@endif
