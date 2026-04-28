@php
    $wheelItems = $batch->batchItems->filter(fn ($i) => str_contains(strtolower($i->productVariant->name), 'wheel'));
    $packItems = $batch->batchItems->filter(fn ($i) => ! str_contains(strtolower($i->productVariant->name), 'wheel'));

    $wheelBreakdowns = $wheelItems->map(function ($item) {
        $produced = (int) $item->quantity_produced;
        $cut = (int) ($item->source_cutting_logs_count ?? 0);
        $sold = max(0, $produced - (int) $item->quantity_remaining - $cut);
        $remaining = max(0, $produced - $cut - $sold);
        $allocated = max(0, min($remaining, (int) ($item->quantity_currently_allocated ?? 0)));
        $free = max(0, $remaining - $allocated);
        return compact('produced', 'cut', 'sold', 'remaining', 'allocated', 'free') + [
            'name' => $item->productVariant->name,
            'item' => $item,
        ];
    });

    $packBreakdowns = $packItems->map(function ($item) {
        $produced = (int) $item->quantity_produced;
        $remaining = (int) $item->quantity_remaining;
        $sold = max(0, $produced - $remaining);
        $allocated = max(0, min($remaining, (int) ($item->quantity_currently_allocated ?? 0)));
        $free = max(0, $remaining - $allocated);
        return compact('produced', 'remaining', 'sold', 'allocated', 'free') + ['name' => $item->productVariant->name];
    })->filter(fn ($b) => $b['produced'] > 0);

    $cuttableWheels = $wheelItems->filter(fn ($i) => $i->quantity_remaining > 0);
@endphp

<details class="mf-panel">
    <summary class="list-none cursor-pointer select-none">
        <div class="flex flex-wrap items-start gap-4 px-4 py-3" style="background: var(--bg); border-bottom: 1px solid var(--line-2);">
            <svg class="chevron transition-transform duration-200 mt-1 flex-shrink-0" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--muted);">
                <path d="M9 5l7 7-7 7" />
            </svg>

            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <h4 class="text-[15px] font-mono font-semibold">{{ $batch->batch_code }}</h4>
                    <span class="text-[13px]" style="color: var(--muted);">{{ $batch->product->name }}</span>
                    <span class="mf-tag mf-tag-accent">Ready to cut</span>
                </div>

                <div class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-1 text-[12px]">
                    <div>
                        <span style="color: var(--muted);">Produced:</span>
                        <span class="font-mono">{{ $batch->production_date->format('d/m/Y') }}</span>
                    </div>
                    <div>
                        <span style="color: var(--muted);">Ready:</span>
                        @if($batch->ready_date)
                            <span class="font-mono">{{ $batch->ready_date->format('d/m/Y') }}</span>
                        @else
                            <span class="font-medium" style="color: var(--accent-ink);">Immediate</span>
                        @endif
                    </div>
                    <div>
                        <span style="color: var(--muted);">Raw milk:</span>
                        <span class="font-mono">{{ number_format($batch->raw_milk_litres, 1) }}L</span>
                    </div>
                    <div>
                        <span style="color: var(--muted);">Wheels left:</span>
                        <span class="font-mono">{{ $cuttableWheels->sum('quantity_remaining') }}</span>
                    </div>
                </div>

                @if($wheelBreakdowns->isNotEmpty())
                    <div class="mt-2 space-y-1">
                        @foreach($wheelBreakdowns as $wb)
                            <div class="flex items-center flex-wrap gap-1">
                                @if($wheelBreakdowns->count() > 1)
                                    <span class="text-[11.5px] mr-1" style="color: var(--muted);">{{ $wb['name'] }}:</span>
                                @endif
                                @for($i = 0; $i < $wb['cut']; $i++)
                                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-gray-400 border border-gray-600" title="Cut to vac packs"></span>
                                @endfor
                                @for($i = 0; $i < $wb['sold']; $i++)
                                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-gray-900 border border-black" title="Whole wheel sold"></span>
                                @endfor
                                @for($i = 0; $i < $wb['allocated']; $i++)
                                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-500 border border-amber-700" title="Wheel allocated to an order"></span>
                                @endfor
                                @for($i = 0; $i < $wb['free']; $i++)
                                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-yellow-400 border border-yellow-600" title="Wheel free in stock"></span>
                                @endfor
                                @if($wb['produced'] > 0)
                                    <span class="ml-2 text-[11.5px] font-mono" style="color: var(--muted);">
                                        {{ $wb['free'] }} · {{ $wb['allocated'] }} · {{ $wb['cut'] }} · {{ $wb['sold'] }}
                                        <span style="color: var(--faint);">/ {{ $wb['produced'] }}</span>
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($packBreakdowns->isNotEmpty())
                    <div class="mt-2 space-y-1">
                        @foreach($packBreakdowns as $pb)
                            @php
                                $freePct = $pb['produced'] > 0 ? ($pb['free'] / $pb['produced']) * 100 : 0;
                                $allocatedPct = $pb['produced'] > 0 ? ($pb['allocated'] / $pb['produced']) * 100 : 0;
                                $soldPct = $pb['produced'] > 0 ? ($pb['sold'] / $pb['produced']) * 100 : 0;
                            @endphp
                            <div class="flex items-center flex-wrap gap-2">
                                <span class="text-[11.5px]" style="color: var(--muted);">{{ $pb['name'] }}:</span>
                                <span class="inline-flex w-32 h-2 rounded-full bg-gray-200 overflow-hidden border border-gray-300" title="{{ $pb['free'] }} free · {{ $pb['allocated'] }} allocated · {{ $pb['sold'] }} sold">
                                    <span class="h-full bg-yellow-400" style="width: {{ $freePct }}%"></span>
                                    <span class="h-full bg-amber-500" style="width: {{ $allocatedPct }}%"></span>
                                    <span class="h-full bg-gray-900" style="width: {{ $soldPct }}%"></span>
                                </span>
                                <span class="text-[11.5px] font-mono" style="color: var(--muted);">
                                    {{ $pb['free'] }} <span style="color: var(--faint);">/</span> {{ $pb['produced'] }} packs
                                    @if($pb['allocated'] > 0)
                                        <span style="color: var(--faint);">· {{ $pb['allocated'] }} allocated</span>
                                    @endif
                                    @if($pb['sold'] > 0)
                                        <span style="color: var(--faint);">· {{ $pb['sold'] }} sold</span>
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-2 text-[13px] flex-shrink-0" onclick="event.stopPropagation()">
                <a href="{{ route('batches.show', $batch) }}" class="mf-link">View batch</a>
            </div>
        </div>
    </summary>

    <div class="px-4 py-3 space-y-4">
        <div>
            <h5 class="text-[11.5px] font-medium uppercase mb-2" style="color: var(--muted); letter-spacing: 0.4px;">Available wheels</h5>
            @if($cuttableWheels->count() > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach($cuttableWheels as $batchItem)
                        <div class="rounded-md p-3" style="background: var(--bg); border: 1px solid var(--line);">
                            <div class="flex justify-between items-center gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium truncate text-[13px]">{{ $batchItem->productVariant->name }}</p>
                                    <p class="text-[12px]" style="color: var(--muted);">{{ $batchItem->quantity_remaining }} {{ Str::plural('wheel', $batchItem->quantity_remaining) }} remaining</p>
                                    @if($batchItem->unit_weight_kg)
                                        <p class="text-[11.5px] font-mono" style="color: var(--faint);">{{ number_format($batchItem->unit_weight_kg, 2) }} kg each</p>
                                    @endif
                                </div>
                                @can('create', App\Models\CheeseCuttingLog::class)
                                    <a href="{{ route('cheese-cutting.create', $batchItem) }}" class="mf-btn-primary flex-shrink-0">
                                        Cut wheel
                                    </a>
                                @endcan
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-[12.5px] text-center py-4 rounded-md" style="color: var(--muted); border: 1px dashed var(--line);">
                    No whole wheels remaining — all have been cut or sold.
                </div>
            @endif
        </div>

        @if($packBreakdowns->isNotEmpty())
            <div class="pt-4" style="border-top: 1px solid var(--line-2);">
                <h5 class="text-[11.5px] font-medium uppercase mb-2" style="color: var(--muted); letter-spacing: 0.4px;">Vacuum packs created</h5>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach($packBreakdowns as $pb)
                        <div class="rounded-md p-3" style="background: var(--info-soft); border: 1px solid oklch(0.92 0.04 235);">
                            <p class="font-medium text-[13px]">{{ $pb['name'] }}</p>
                            <p class="text-[12px]" style="color: var(--muted);">{{ $pb['produced'] }} packs produced</p>
                            <p class="text-[12px]" style="color: var(--accent-ink);">{{ $pb['remaining'] }} remaining</p>
                            @if($pb['sold'] > 0)
                                <p class="text-[11.5px]" style="color: var(--faint);">{{ $pb['sold'] }} sold</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</details>
