<x-app-layout>
    @php
        $subGroups = $readyBatches->groupBy(fn ($b) => $b->product->name)->sortKeys();

        $wheelStatsFor = function ($batch) {
            $stats = ['produced' => 0, 'remaining' => 0, 'cut' => 0, 'sold' => 0];
            foreach ($batch->batchItems as $item) {
                if (! str_contains(strtolower($item->productVariant->name), 'wheel')) {
                    continue;
                }
                $produced = (int) $item->quantity_produced;
                $cut = (int) ($item->source_cutting_logs_count ?? 0);
                $sold = max(0, $produced - (int) $item->quantity_remaining - $cut);
                $remaining = max(0, $produced - $cut - $sold);
                $stats['produced'] += $produced;
                $stats['remaining'] += $remaining;
                $stats['cut'] += $cut;
                $stats['sold'] += $sold;
            }
            return $stats;
        };
    @endphp

    <x-slot name="header">Cheese cutting</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Cheese cutting</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Wheels ready to be cut into vacuum packs.</div>
            </div>
        </div>

        @if (session('success'))
            <div class="mf-flash mf-flash-success">{{ session('success') }}</div>
        @endif

        <div class="flex items-center gap-3 mb-4 pb-2" style="border-bottom: 1px solid var(--line);">
            <h2 class="text-[16px] font-display font-medium">Ready to cut</h2>
            <span class="mf-tag mf-tag-warn">{{ $readyBatches->count() }} {{ Str::plural('batch', $readyBatches->count()) }}</span>
        </div>

        @forelse ($subGroups as $subLabel => $subBatches)
            @php
                $subBatchCount = $subBatches->count();
                $subWheelTotals = ['produced' => 0, 'remaining' => 0, 'cut' => 0, 'sold' => 0];
                foreach ($subBatches as $b) {
                    $s = $wheelStatsFor($b);
                    foreach ($subWheelTotals as $k => $v) {
                        $subWheelTotals[$k] += $s[$k];
                    }
                }
            @endphp

            <details class="ml-2 mb-4" open>
                <summary class="list-none cursor-pointer select-none">
                    <div class="flex items-center gap-2 flex-wrap mb-3 pb-1" style="border-bottom: 1px solid var(--line-2);">
                        <svg class="chevron transition-transform duration-200" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--muted);">
                            <path d="M9 5l7 7-7 7" />
                        </svg>
                        <h3 class="text-[14px] font-semibold">{{ $subLabel }}</h3>
                        <span class="mf-tag mf-tag-warn">{{ $subBatchCount }} {{ Str::plural('batch', $subBatchCount) }}</span>
                        @if ($subWheelTotals['produced'] > 0)
                            <span class="inline-flex items-center gap-2 text-[12px] ml-2" style="color: var(--muted);">
                                <span class="inline-flex items-center gap-1">
                                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-yellow-400 border border-yellow-600"></span>
                                    {{ $subWheelTotals['remaining'] }}
                                </span>
                                <span class="inline-flex items-center gap-1">
                                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-gray-400 border border-gray-600"></span>
                                    {{ $subWheelTotals['cut'] }}
                                </span>
                                <span class="inline-flex items-center gap-1">
                                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-gray-900 border border-black"></span>
                                    {{ $subWheelTotals['sold'] }}
                                </span>
                                <span style="color: var(--faint);">/ {{ $subWheelTotals['produced'] }} wheels</span>
                            </span>
                        @endif
                    </div>
                </summary>
                <div class="space-y-3">
                    @foreach ($subBatches as $batch)
                        @include('cheese-cutting.partials.batch-card', ['batch' => $batch])
                    @endforeach
                </div>
            </details>
        @empty
            <div class="mf-panel p-10 text-center">
                <h3 class="text-[14px] font-semibold">No cheese ready to cut</h3>
                <p class="mt-1 text-[13px]" style="color: var(--muted);">
                    Cheese batches need to mature before they can be cut. Check back when batches reach their ready date.
                </p>
                <div class="mt-4">
                    <a href="{{ route('batches.index', ['type' => 'cheese']) }}" class="mf-btn-secondary">View all cheese batches</a>
                </div>
            </div>
        @endforelse
    </div>

    <style>
        details > summary { list-style: none; }
        details > summary::-webkit-details-marker { display: none; }
        details[open] > summary .chevron { transform: rotate(90deg); }
    </style>
</x-app-layout>
