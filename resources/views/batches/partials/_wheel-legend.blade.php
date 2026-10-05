{{--
    Group-header totals for a cheese variety: the same states as the strip,
    summed over its batches. $totals is a summed BatchItem::wheelStates() array.
--}}
@if(($totals['produced'] ?? 0) > 0)
    <span class="inline-flex items-center gap-2 text-[12px] ml-2" style="color: var(--muted);">
        <span class="inline-flex items-center gap-1" title="Free in stock">
            <span class="inline-block w-2.5 h-2.5 rounded-full bg-yellow-400 border border-yellow-600"></span>
            {{ $totals['free'] }}
        </span>
        <span class="inline-flex items-center gap-1" title="Allocated to orders">
            <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-500 border border-amber-700"></span>
            {{ $totals['allocated'] }}
        </span>
        @if($totals['maturing'] > 0)
            <span class="inline-flex items-center gap-1" title="Set aside to mature">
                <span class="inline-block w-2.5 h-2.5 rounded-full border" style="background: var(--state-maturing); border-color: oklch(0.42 0.11 56);"></span>
                {{ $totals['maturing'] }}
            </span>
        @endif
        <span class="inline-flex items-center gap-1" title="Cut to vac packs">
            <span class="inline-block w-2.5 h-2.5 rounded-full bg-gray-400 border border-gray-600"></span>
            {{ $totals['cut'] }}
        </span>
        @if($totals['converted'] > 0)
            <span class="inline-flex items-center gap-1" title="Converted to mature cheese">
                <span class="inline-block w-2.5 h-2.5 rounded-full border" style="background: var(--state-converted); border-color: #5B21B6;"></span>
                {{ $totals['converted'] }}
            </span>
        @endif
        @if(($totals['written_off'] ?? 0) > 0)
            <span class="inline-flex items-center gap-1" title="Written off">
                <span class="inline-block w-2.5 h-2.5 rounded-full border" style="background: var(--state-written-off); border-color: oklch(0.45 0.15 25);"></span>
                {{ $totals['written_off'] }}
            </span>
        @endif
        <span class="inline-flex items-center gap-1" title="Whole wheels sold">
            <span class="inline-block w-2.5 h-2.5 rounded-full bg-gray-900 border border-black"></span>
            {{ $totals['sold'] }}
        </span>
        <span style="color: var(--faint);">/ {{ $totals['produced'] }} wheels</span>
    </span>
@endif
