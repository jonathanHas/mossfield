<x-app-layout>
    @php
        $subGroups = $readyBatches->groupBy(fn ($b) => $b->product->name)->sortKeys();

        // Per-variety totals of the wheel strip (BatchItem::wheelStates()).
        $wheelStatsFor = function ($batch) {
            $stats = array_fill_keys(['produced', 'free', 'allocated', 'maturing', 'cut', 'converted', 'written_off', 'sold'], 0);
            foreach ($batch->batchItems as $item) {
                if (! $item->productVariant->isWheel()) {
                    continue;
                }
                foreach ($item->wheelStates() as $k => $v) {
                    $stats[$k] += $v;
                }
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

        <div class="flex items-center gap-3 mb-4 pb-2" style="border-bottom: 1px solid var(--line);">
            <h2 class="text-[16px] font-display font-medium">Ready to cut</h2>
            <span class="mf-tag mf-tag-warn">{{ $readyBatches->count() }} {{ Str::plural('batch', $readyBatches->count()) }}</span>
        </div>

        @forelse ($subGroups as $subLabel => $subBatches)
            @php
                $subBatchCount = $subBatches->count();
                $subWheelTotals = array_fill_keys(['produced', 'free', 'allocated', 'maturing', 'cut', 'converted', 'written_off', 'sold'], 0);
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
                        @include('batches.partials._wheel-legend', ['totals' => $subWheelTotals])
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
                @if($spokenFor ?? false)
                    <h3 class="text-[14px] font-semibold">Every ready wheel is spoken for</h3>
                    <p class="mt-1 text-[13px]" style="color: var(--muted);">
                        There are matured batches, but all of their remaining wheels are allocated to orders or set aside for maturing.
                        Release a hold or wait for new stock.
                    </p>
                    <div class="mt-4 flex justify-center gap-2">
                        <a href="{{ route('cheese-conversion.index') }}" class="mf-btn-secondary">Mature Conversion</a>
                        <a href="{{ route('batches.index', ['type' => 'cheese']) }}" class="mf-btn-secondary">View all cheese batches</a>
                    </div>
                @else
                    <h3 class="text-[14px] font-semibold">No cheese ready to cut</h3>
                    <p class="mt-1 text-[13px]" style="color: var(--muted);">
                        Cheese batches need to mature before they can be cut. Check back when batches reach their ready date.
                    </p>
                    <div class="mt-4">
                        <a href="{{ route('batches.index', ['type' => 'cheese']) }}" class="mf-btn-secondary">View all cheese batches</a>
                    </div>
                @endif
            </div>
        @endforelse
    </div>

    <style>
        details > summary { list-style: none; }
        details > summary::-webkit-details-marker { display: none; }
        details[open] > summary .chevron { transform: rotate(90deg); }
    </style>
</x-app-layout>
