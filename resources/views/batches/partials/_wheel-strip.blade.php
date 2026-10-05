{{--
    One row of the at-a-glance wheel strip: a dot per wheel in state order,
    then a labelled numeric summary. $wb comes from BatchItem::wheelStates()
    (+ 'name'); $showName labels the row when a batch has more than one wheel
    variant. Shared by the /batches and /cheese-cutting cards.
--}}
<div class="flex items-center flex-wrap gap-1">
    @if($showName ?? false)
        <span class="text-[11.5px] mr-1" style="color: var(--muted);">{{ $wb['name'] }}:</span>
    @endif
    @for($i = 0; $i < $wb['cut']; $i++)
        <span class="inline-block w-2.5 h-2.5 rounded-full bg-gray-400 border border-gray-600" title="Cut to vac packs"></span>
    @endfor
    @for($i = 0; $i < $wb['converted']; $i++)
        <span class="inline-block w-2.5 h-2.5 rounded-full border" style="background: var(--state-converted); border-color: #5B21B6;" title="Converted to mature cheese"></span>
    @endfor
    @for($i = 0; $i < ($wb['written_off'] ?? 0); $i++)
        <span class="inline-block w-2.5 h-2.5 rounded-full border" style="background: var(--state-written-off); border-color: oklch(0.45 0.15 25);" title="Written off"></span>
    @endfor
    @for($i = 0; $i < $wb['sold']; $i++)
        <span class="inline-block w-2.5 h-2.5 rounded-full bg-gray-900 border border-black" title="Whole wheel sold"></span>
    @endfor
    @for($i = 0; $i < $wb['allocated']; $i++)
        <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-500 border border-amber-700" title="Wheel allocated to an order"></span>
    @endfor
    @for($i = 0; $i < $wb['maturing']; $i++)
        <span class="inline-block w-2.5 h-2.5 rounded-full border" style="background: var(--state-maturing); border-color: oklch(0.42 0.11 56);" title="Wheel set aside to mature"></span>
    @endfor
    @for($i = 0; $i < $wb['free']; $i++)
        <span class="inline-block w-2.5 h-2.5 rounded-full bg-yellow-400 border border-yellow-600" title="Wheel free in stock"></span>
    @endfor
    @if($wb['produced'] > 0)
        {{-- Every number carries its state, and zero states are dropped, so
             nothing reads as an unexplained figure. --}}
        <span class="ml-2 text-[11.5px] font-mono" style="color: var(--muted);">
            {{ $wb['free'] }} free
            @if($wb['allocated'] > 0) · {{ $wb['allocated'] }} allocated @endif
            @if($wb['maturing'] > 0) · <span style="color: var(--state-maturing);">{{ $wb['maturing'] }} maturing</span> @endif
            @if($wb['cut'] > 0) · {{ $wb['cut'] }} cut @endif
            @if($wb['converted'] > 0) · <span style="color: var(--state-converted);">{{ $wb['converted'] }} converted</span> @endif
            @if(($wb['written_off'] ?? 0) > 0) · <span style="color: var(--state-written-off);">{{ $wb['written_off'] }} written off</span> @endif
            @if($wb['sold'] > 0) · {{ $wb['sold'] }} sold @endif
            <span style="color: var(--faint);">/ {{ $wb['produced'] }}</span>
        </span>
    @endif
</div>
