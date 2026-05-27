@props(['item', 'order'])
{{--
    One order line, styled to the Picking-page v2 design: a
    [44px thumb | 1fr body | auto price] grid, with the body carrying the
    product name + a caller-supplied `summary` slot, and an optional default
    slot (the fulfilment panel) spanning the full row beneath.

    Any extra attributes (e.g. Alpine `x-data`) are merged onto the root so the
    caller can scope a collapse toggle across both the summary and the panel.
--}}
@php
    $variant = $item->productVariant;
    $product = $variant->product;
    $pname = strtolower($product->name);

    // Gradient fallback thumbnail keyed to product family (image used when present).
    if (str_contains($pname, 'garlic')) {
        $thumbClass = 'mf-thumb--garlic';
        $thumbLabel = 'GARLIC';
    } elseif ($product->type === 'cheese') {
        $thumbClass = 'mf-thumb--cheese';
        $thumbLabel = 'CHEESE';
    } elseif ($product->type === 'milk') {
        $thumbClass = 'mf-thumb--milk';
        $thumbLabel = 'MILK';
    } elseif ($product->type === 'yoghurt') {
        $thumbClass = 'mf-thumb--yog';
        $thumbLabel = 'YOGHURT';
    } else {
        $thumbClass = '';
        $thumbLabel = strtoupper($product->type ?? '');
    }
@endphp

<div {{ $attributes->merge(['class' => 'grid grid-cols-[44px_1fr_auto] gap-4 items-start py-[18px]']) }}
     style="border-top: 1px solid var(--line);">
    <div class="mf-thumb {{ $thumbClass }}">
        @if($variant->display_image_url)
            <img src="{{ $variant->display_image_url }}" alt="{{ $product->name }}">
        @else
            {{ $thumbLabel }}
        @endif
    </div>

    <div class="min-w-0">
        <div class="flex items-baseline gap-2 flex-wrap">
            <span class="text-[14px] font-semibold" style="letter-spacing: -0.005em;">{{ $product->name }}</span>
            @if($item->isVariableWeight())
                <span class="mf-tag mf-tag-neutral">Variable weight</span>
            @endif
            <span class="text-[12.5px]" style="color: var(--muted);">· {{ $variant->name }}</span>
        </div>
        {{ $summary }}
    </div>

    @can('see-financials')
        <div class="text-right">
            <div class="text-[14px] font-semibold font-mono">€{{ number_format($item->invoiceable_total, 2) }}</div>
            <div class="text-[12px] mt-0.5 font-mono" style="color: var(--muted);">
                @if($item->isPricedByWeight())
                    @if($item->weight_fulfilled_kg > 0)
                        {{ number_format($item->weight_fulfilled_kg, 3) }} kg × €{{ number_format($item->unit_price, 2) }}
                    @else
                        ~ {{ number_format(($variant->weight_kg ?? 0) * $item->quantity_ordered, 3) }} kg
                    @endif
                @else
                    {{ $item->quantity_ordered }} × €{{ number_format($item->unit_price, 2) }}
                @endif
            </div>
        </div>
    @else
        <div></div>
    @endcan

    @if(trim($slot) !== '')
        <div class="col-span-3">{{ $slot }}</div>
    @endif
</div>
