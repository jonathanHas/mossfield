@php
    $isReady = $batch->isReadyToSell();
    $isExpired = $batch->isExpired();
    $isCheese = $batch->product->type === 'cheese';
    $wheelItems = $isCheese
        ? $batch->batchItems->filter(fn ($i) => str_contains(strtolower($i->productVariant->name), 'wheel'))
        : collect();

    $packItems = $isCheese
        ? $batch->batchItems->filter(fn ($i) => ! str_contains(strtolower($i->productVariant->name), 'wheel'))
        : collect();

    $wheelBreakdowns = $wheelItems->map(function ($item) {
        $produced = (int) $item->quantity_produced;
        $cut = (int) ($item->source_cutting_logs_count ?? 0);
        $sold = max(0, $produced - (int) $item->quantity_remaining - $cut);
        $remaining = max(0, $produced - $cut - $sold);
        $allocated = max(0, min($remaining, (int) ($item->quantity_currently_allocated ?? 0)));
        $free = max(0, $remaining - $allocated);
        return compact('produced', 'cut', 'sold', 'remaining', 'allocated', 'free') + ['name' => $item->productVariant->name];
    });

    $packBreakdowns = $packItems->map(function ($item) {
        $produced = (int) $item->quantity_produced;
        $remaining = (int) $item->quantity_remaining;
        $sold = max(0, $produced - $remaining);
        $allocated = max(0, min($remaining, (int) ($item->quantity_currently_allocated ?? 0)));
        $free = max(0, $remaining - $allocated);
        return compact('produced', 'remaining', 'sold', 'allocated', 'free') + ['name' => $item->productVariant->name];
    })->filter(fn ($b) => $b['produced'] > 0);

    $statusTone = match($batch->status) {
        'active' => 'accent',
        'sold_out' => 'danger',
        default => 'neutral',
    };
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
                    <span class="mf-tag mf-tag-{{ $statusTone }}">{{ ucfirst(str_replace('_', ' ', $batch->status)) }}</span>
                    @if($isExpired)
                        <span class="mf-tag mf-tag-danger">Expired</span>
                    @elseif(!$isReady)
                        <span class="mf-tag mf-tag-warn">Maturing</span>
                    @else
                        <span class="mf-tag mf-tag-accent">Ready</span>
                    @endif
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
                            @if(!$isReady)
                                <span style="color: var(--warn-ink);">({{ $batch->ready_date->diffForHumans() }})</span>
                            @endif
                        @else
                            <span class="font-medium" style="color: var(--accent-ink);">Now</span>
                        @endif
                    </div>
                    <div>
                        <span style="color: var(--muted);">Raw milk:</span>
                        <span class="font-mono">{{ number_format($batch->raw_milk_litres, 1) }}L</span>
                    </div>
                    <div>
                        <span style="color: var(--muted);">Stock:</span>
                        <span class="font-mono">{{ $batch->remaining_stock }}</span>
                    </div>
                    @if($batch->wheels_produced && $isCheese)
                        <div>
                            <span style="color: var(--muted);">Wheels:</span>
                            <span class="font-mono">{{ $batch->wheels_produced }}</span>
                        </div>
                    @endif
                    @if($batch->expiry_date)
                        <div>
                            <span style="color: var(--muted);">Expires:</span>
                            <span class="font-mono">{{ $batch->expiry_date->format('d/m/Y') }}</span>
                        </div>
                    @endif
                </div>

                @if($isCheese && $wheelBreakdowns->isNotEmpty())
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

                @if($isCheese && $packBreakdowns->isNotEmpty())
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
                <a href="{{ route('batches.show', $batch) }}" class="mf-link">View</a>
                @can('update', $batch)
                    <span style="color: var(--faint);">·</span>
                    <a href="{{ route('batches.edit', $batch) }}" class="mf-link">Edit</a>
                @endcan
                @can('delete', $batch)
                    <span style="color: var(--faint);">·</span>
                    <form action="{{ route('batches.destroy', $batch) }}" method="POST" class="inline">
                        @csrf @method('DELETE')
                        <button type="submit" class="mf-link" style="color: var(--danger);"
                                onclick="return confirm('Are you sure?')">Delete</button>
                    </form>
                @endcan
            </div>
        </div>
    </summary>

    <div class="px-4 py-3">
        <div class="flex justify-between items-center mb-2">
            <h5 class="text-[11.5px] font-medium uppercase" style="color: var(--muted); letter-spacing: 0.4px;">Batch items</h5>
        </div>

        @if($batch->batchItems->count() > 0)
            <div class="overflow-x-auto rounded-md" style="border: 1px solid var(--line);">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th class="mf-th">Variant</th>
                            <th class="mf-th">Size</th>
                            <th class="mf-th">Unit weight</th>
                            <th class="mf-th text-right">Produced</th>
                            <th class="mf-th text-right">Remaining</th>
                            <th class="mf-th text-right" title="Of remaining, this many are reserved for open orders">Allocated</th>
                            <th class="mf-th text-right">Sold</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($batch->batchItems as $item)
                            @php
                                $rowAllocated = max(0, min((int) $item->quantity_remaining, (int) ($item->quantity_currently_allocated ?? 0)));
                            @endphp
                            <tr style="border-top: 1px solid var(--line-2);">
                                <td class="mf-td">
                                    {{ $item->productVariant->name }}
                                    @if($item->productVariant->is_variable_weight)
                                        <span class="mf-tag mf-tag-warn ml-1" title="Weighed at fulfillment">Var. wt</span>
                                    @endif
                                </td>
                                <td class="mf-td font-mono" style="color: var(--muted);">{{ $item->productVariant->size }}</td>
                                <td class="mf-td font-mono" style="color: var(--muted);">{{ $item->unit_weight_kg ? number_format($item->unit_weight_kg, 3) . ' kg' : '—' }}</td>
                                <td class="mf-td font-mono text-right">{{ $item->quantity_produced }}</td>
                                <td class="mf-td font-mono text-right">
                                    <span style="color: {{ $item->quantity_remaining === 0 ? 'var(--danger)' : 'var(--ink)' }};">
                                        {{ $item->quantity_remaining }}
                                    </span>
                                </td>
                                <td class="mf-td font-mono text-right" style="color: {{ $rowAllocated > 0 ? 'var(--warn-ink)' : 'var(--faint)' }};">{{ $rowAllocated }}</td>
                                <td class="mf-td font-mono text-right" style="color: var(--muted);">{{ $item->quantity_sold }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-[13px] text-center py-4 rounded-md" style="color: var(--muted); border: 1px dashed var(--line);">
                No items recorded for this batch.
            </div>
        @endif

        @if($batch->notes)
            <div class="mt-3 text-[12px] rounded-md px-3 py-2" style="background: var(--bg); border: 1px solid var(--line); color: var(--ink-2);">
                <span class="font-semibold" style="color: var(--ink);">Notes:</span> {{ $batch->notes }}
            </div>
        @endif
    </div>
</details>
