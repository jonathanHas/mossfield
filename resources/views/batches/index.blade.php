<x-app-layout>
    @php
        $typeMeta = [
            'milk'    => ['label' => 'Milk',    'tone' => 'info'],
            'yoghurt' => ['label' => 'Yoghurt', 'tone' => 'accent'],
            'cheese'  => ['label' => 'Cheese',  'tone' => 'warn'],
        ];

        $groupedBatches = $batches->getCollection()->groupBy(fn ($batch) => $batch->product->type);
        $typeOrder = ['milk', 'yoghurt', 'cheese'];
        $groupedBatches = $groupedBatches->sortBy(fn ($_, $type) => array_search($type, $typeOrder) === false ? 99 : array_search($type, $typeOrder));

        $wheelStatsFor = function ($batch) {
            $stats = ['produced' => 0, 'free' => 0, 'allocated' => 0, 'cut' => 0, 'sold' => 0];
            foreach ($batch->batchItems as $item) {
                if (! str_contains(strtolower($item->productVariant->name), 'wheel')) {
                    continue;
                }
                $produced = (int) $item->quantity_produced;
                $cut = (int) ($item->source_cutting_logs_count ?? 0);
                $sold = max(0, $produced - (int) $item->quantity_remaining - $cut);
                $remaining = max(0, $produced - $cut - $sold);
                $allocated = max(0, min($remaining, (int) ($item->quantity_currently_allocated ?? 0)));
                $free = max(0, $remaining - $allocated);
                $stats['produced'] += $produced;
                $stats['free'] += $free;
                $stats['allocated'] += $allocated;
                $stats['cut'] += $cut;
                $stats['sold'] += $sold;
            }
            return $stats;
        };
    @endphp

    <x-slot name="header">Batches</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Production batches</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Grouped by product type, with wheel & vac-pack status at a glance.</div>
            </div>
            @can('create', App\Models\Batch::class)
                <a href="{{ route('batches.create') }}" class="mf-btn-primary">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                    New batch
                </a>
            @endcan
        </div>

        @if (session('success'))
            <div class="mf-flash mf-flash-success">{{ session('success') }}</div>
        @endif

        <div class="mf-panel mb-4">
            <form method="GET" action="{{ route('batches.index') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 p-4">
                <div>
                    <label for="type" class="mf-label">Type</label>
                    <select name="type" id="type" class="mf-select">
                        <option value="">All types</option>
                        <option value="milk" {{ request('type') === 'milk' ? 'selected' : '' }}>Milk</option>
                        <option value="yoghurt" {{ request('type') === 'yoghurt' ? 'selected' : '' }}>Yoghurt</option>
                        <option value="cheese" {{ request('type') === 'cheese' ? 'selected' : '' }}>Cheese</option>
                    </select>
                </div>
                <div>
                    <label for="status" class="mf-label">Status</label>
                    <select name="status" id="status" class="mf-select">
                        <option value="">All</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="sold_out" {{ request('status') === 'sold_out' ? 'selected' : '' }}>Sold out</option>
                        <option value="expired" {{ request('status') === 'expired' ? 'selected' : '' }}>Expired</option>
                    </select>
                </div>
                <div>
                    <label for="maturation_status" class="mf-label">Maturation</label>
                    <select name="maturation_status" id="maturation_status" class="mf-select">
                        <option value="">All</option>
                        <option value="ready" {{ request('maturation_status') === 'ready' ? 'selected' : '' }}>Ready to sell</option>
                        <option value="maturing" {{ request('maturation_status') === 'maturing' ? 'selected' : '' }}>Still maturing</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="mf-btn-secondary w-full justify-center">Filter</button>
                </div>
            </form>
        </div>

        @forelse ($groupedBatches as $type => $typeBatches)
            @php
                $meta = $typeMeta[$type] ?? ['label' => ucfirst($type), 'tone' => 'neutral'];
                $batchCount = $typeBatches->count();
                $itemCount = $typeBatches->sum(fn ($b) => $b->batchItems->count());

                $subGroups = $type === 'cheese'
                    ? $typeBatches->groupBy(fn ($b) => $b->product->name)->sortKeys()
                    : collect([null => $typeBatches]);
            @endphp

            <details class="batch-group group mb-6" open>
                <summary class="list-none cursor-pointer select-none">
                    <div class="flex items-center gap-3 mb-3 pb-2" style="border-bottom: 1px solid var(--line);">
                        <svg class="chevron transition-transform duration-200" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--muted);">
                            <path d="M9 5l7 7-7 7" />
                        </svg>
                        <h2 class="text-[16px] font-display font-medium">{{ $meta['label'] }}</h2>
                        <span class="mf-tag mf-tag-{{ $meta['tone'] }}">
                            {{ $batchCount }} {{ Str::plural('batch', $batchCount) }} · {{ $itemCount }} {{ Str::plural('item', $itemCount) }}
                        </span>
                    </div>
                </summary>

                <div class="space-y-4">
                    @foreach ($subGroups as $subLabel => $subBatches)
                        @php
                            $subBatchCount = $subBatches->count();
                            $subWheelTotals = null;
                            if ($type === 'cheese') {
                                $subWheelTotals = ['produced' => 0, 'free' => 0, 'allocated' => 0, 'cut' => 0, 'sold' => 0];
                                foreach ($subBatches as $b) {
                                    $s = $wheelStatsFor($b);
                                    foreach ($subWheelTotals as $k => $v) {
                                        $subWheelTotals[$k] += $s[$k];
                                    }
                                }
                            }
                        @endphp

                        @if ($type === 'cheese')
                            <details class="ml-2" open>
                                <summary class="list-none cursor-pointer select-none">
                                    <div class="flex items-center gap-2 flex-wrap mb-3 pb-1" style="border-bottom: 1px solid var(--line-2);">
                                        <svg class="chevron transition-transform duration-200" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--muted);">
                                            <path d="M9 5l7 7-7 7" />
                                        </svg>
                                        <h3 class="text-[14px] font-semibold">{{ $subLabel }}</h3>
                                        <span class="mf-tag mf-tag-warn">{{ $subBatchCount }} {{ Str::plural('batch', $subBatchCount) }}</span>
                                        @if ($subWheelTotals && $subWheelTotals['produced'] > 0)
                                            <span class="inline-flex items-center gap-2 text-[12px] ml-2" style="color: var(--muted);">
                                                <span class="inline-flex items-center gap-1" title="Free in stock">
                                                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-yellow-400 border border-yellow-600"></span>
                                                    {{ $subWheelTotals['free'] }}
                                                </span>
                                                <span class="inline-flex items-center gap-1" title="Allocated to orders">
                                                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-500 border border-amber-700"></span>
                                                    {{ $subWheelTotals['allocated'] }}
                                                </span>
                                                <span class="inline-flex items-center gap-1" title="Cut to vac packs">
                                                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-gray-400 border border-gray-600"></span>
                                                    {{ $subWheelTotals['cut'] }}
                                                </span>
                                                <span class="inline-flex items-center gap-1" title="Whole wheels sold">
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
                                        @include('batches.partials.batch-card', ['batch' => $batch, 'tone' => $meta['tone']])
                                    @endforeach
                                </div>
                            </details>
                        @else
                            <div class="space-y-3">
                                @foreach ($subBatches as $batch)
                                    @include('batches.partials.batch-card', ['batch' => $batch, 'tone' => $meta['tone']])
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </div>
            </details>
        @empty
            <div class="mf-panel p-10 text-center">
                <h3 class="text-[14px] font-semibold">No batches found</h3>
                <p class="mt-1 text-[13px]" style="color: var(--muted);">Get started by creating your first production batch.</p>
                @can('create', App\Models\Batch::class)
                    <div class="mt-4">
                        <a href="{{ route('batches.create') }}" class="mf-btn-primary">Create first batch</a>
                    </div>
                @endcan
            </div>
        @endforelse

        @if ($batches->hasPages())
            <div>{{ $batches->withQueryString()->links() }}</div>
        @endif
    </div>

    <style>
        details > summary { list-style: none; }
        details > summary::-webkit-details-marker { display: none; }
        details[open] > summary .chevron { transform: rotate(90deg); }
    </style>
</x-app-layout>
