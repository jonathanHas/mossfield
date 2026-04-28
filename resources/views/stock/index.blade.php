<x-app-layout>
    <x-slot name="header">Stock</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Stock overview</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Real-time stock value and maturation timeline.</div>
            </div>
            <div class="text-right">
                <div class="text-[11.5px] uppercase font-medium" style="color: var(--muted); letter-spacing: 0.4px;">Total stock value</div>
                <div class="text-[24px] font-mono font-semibold" style="color: var(--accent-ink);">€{{ number_format($totalValue, 2) }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
            <div class="mf-panel">
                <div class="px-4 py-3">
                    <div class="text-[11.5px] uppercase font-medium" style="color: var(--muted); letter-spacing: 0.4px;">Ready to sell</div>
                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="text-[26px] font-semibold" style="color: var(--accent-ink); letter-spacing: -0.6px;">{{ $readyToSell->count() }}</span>
                        <span class="text-[12px] font-mono" style="color: var(--muted);">€{{ number_format($readyValue, 2) }}</span>
                    </div>
                </div>
            </div>
            <div class="mf-panel">
                <div class="px-4 py-3">
                    <div class="text-[11.5px] uppercase font-medium" style="color: var(--muted); letter-spacing: 0.4px;">Maturing</div>
                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="text-[26px] font-semibold" style="color: var(--warn-ink); letter-spacing: -0.6px;">{{ $maturing->count() }}</span>
                        <span class="text-[12px] font-mono" style="color: var(--muted);">€{{ number_format($maturingValue, 2) }}</span>
                    </div>
                </div>
            </div>
            <div class="mf-panel">
                <div class="mf-panel-header">
                    <div class="text-[13px] font-semibold">By type</div>
                </div>
                <div class="px-4 py-3 space-y-1">
                    @foreach($summaryByType as $summary)
                        <div class="flex justify-between text-[13px]">
                            <span class="capitalize" style="color: var(--muted);">{{ $summary['type'] }}</span>
                            <span class="font-mono">{{ $summary['total_quantity'] }} <span style="color: var(--muted);">· €{{ number_format($summary['total_value'], 0) }}</span></span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="mf-panel mb-4">
            <form method="GET" action="{{ route('stock.index') }}" class="grid grid-cols-1 md:grid-cols-3 gap-3 p-4">
                <div>
                    <label for="product_type" class="mf-label">Product type</label>
                    <select name="product_type" id="product_type" class="mf-select">
                        <option value="">All types</option>
                        <option value="milk" {{ request('product_type') === 'milk' ? 'selected' : '' }}>Milk</option>
                        <option value="yoghurt" {{ request('product_type') === 'yoghurt' ? 'selected' : '' }}>Yoghurt</option>
                        <option value="cheese" {{ request('product_type') === 'cheese' ? 'selected' : '' }}>Cheese</option>
                    </select>
                </div>
                <div>
                    <label for="readiness" class="mf-label">Readiness</label>
                    <select name="readiness" id="readiness" class="mf-select">
                        <option value="">All stock</option>
                        <option value="ready" {{ request('readiness') === 'ready' ? 'selected' : '' }}>Ready to sell</option>
                        <option value="maturing" {{ request('readiness') === 'maturing' ? 'selected' : '' }}>Still maturing</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="mf-btn-secondary w-full justify-center">Apply filters</button>
                </div>
            </form>
        </div>

        @if($readyToSell->count() > 0)
            <div class="mf-panel mb-4">
                <div class="mf-panel-header">
                    <div class="text-[13px] font-semibold">Ready to sell <span style="color: var(--muted); font-weight: 400;">({{ $readyToSell->count() }} items)</span></div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th class="mf-th">Product</th>
                                <th class="mf-th">Batch</th>
                                <th class="mf-th">Variant</th>
                                <th class="mf-th text-right">Stock</th>
                                <th class="mf-th text-right">Unit price</th>
                                <th class="mf-th text-right">Total value</th>
                                <th class="mf-th">Production</th>
                                <th class="mf-th">Expiry</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($readyToSell as $item)
                                @php
                                    $typeTone = match($item->batch->product->type) {
                                        'milk' => 'info',
                                        'yoghurt' => 'accent',
                                        'cheese' => 'warn',
                                        default => 'neutral',
                                    };
                                @endphp
                                <tr style="border-top: 1px solid var(--line-2);">
                                    <td class="mf-td">
                                        <div class="font-medium">{{ $item->batch->product->name }}</div>
                                        <span class="mf-tag mf-tag-{{ $typeTone }} mt-0.5">{{ ucfirst($item->batch->product->type) }}</span>
                                    </td>
                                    <td class="mf-td font-mono">
                                        <a href="{{ route('batches.show', $item->batch) }}" class="mf-link">{{ $item->batch->batch_code }}</a>
                                    </td>
                                    <td class="mf-td">
                                        <div>{{ $item->productVariant->name }}</div>
                                        <div class="text-[11.5px] mt-0.5" style="color: var(--muted);">{{ $item->productVariant->size }} {{ $item->productVariant->unit }}</div>
                                    </td>
                                    <td class="mf-td font-mono text-right font-medium" style="color: var(--accent-ink);">{{ number_format($item->quantity_remaining) }}</td>
                                    <td class="mf-td font-mono text-right">{{ $item->productVariant->price_label }}</td>
                                    <td class="mf-td font-mono text-right font-medium">€{{ number_format($item->productVariant->calculatePrice($item->quantity_remaining), 2) }}</td>
                                    <td class="mf-td font-mono" style="color: var(--muted);">{{ $item->batch->production_date->format('d/m/Y') }}</td>
                                    <td class="mf-td">
                                        @if($item->batch->expiry_date)
                                            @php
                                                $daysUntilExpiry = (int) now()->diffInDays($item->batch->expiry_date, false);
                                                $isExpired = $item->batch->isExpired();
                                                $isExpiringSoon = $daysUntilExpiry <= 7 && $daysUntilExpiry >= 0;
                                                $isExpiringMedium = $daysUntilExpiry <= 14 && $daysUntilExpiry > 7;
                                                $expiryColor = $isExpired ? 'var(--danger)' : ($isExpiringSoon ? 'var(--warn-ink)' : 'var(--ink)');
                                            @endphp
                                            <div class="font-mono" style="color: {{ $expiryColor }};">
                                                {{ $item->batch->expiry_date->format('d/m/Y') }}
                                                @if($isExpired)
                                                    <div class="text-[11px]">EXPIRED</div>
                                                @elseif($isExpiringSoon)
                                                    <div class="text-[11px]">{{ $daysUntilExpiry }}d left</div>
                                                @elseif($isExpiringMedium)
                                                    <div class="text-[11px]" style="color: var(--muted);">{{ $daysUntilExpiry }}d left</div>
                                                @endif
                                            </div>
                                        @else
                                            <span style="color: var(--faint);">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if($maturing->count() > 0)
            <div class="mf-panel mb-4">
                <div class="mf-panel-header">
                    <div class="flex-1">
                        <div class="text-[13px] font-semibold">Maturing stock timeline <span style="color: var(--muted); font-weight: 400;">({{ $maturing->count() }} items)</span></div>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <button id="timeline-view-btn" class="mf-btn-primary text-[12px] px-2.5 py-1">Timeline</button>
                        <button id="calendar-view-btn" class="mf-btn-secondary text-[12px] px-2.5 py-1">Calendar</button>
                        <button id="table-view-btn" class="mf-btn-secondary text-[12px] px-2.5 py-1">Table</button>
                    </div>
                </div>

                <div class="px-4 py-3">
                    <div id="timeline-view" class="space-y-4">
                        <div class="rounded-md p-3" style="background: var(--bg); border: 1px solid var(--line);">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <h4 class="text-[11.5px] font-medium uppercase mb-2" style="color: var(--muted); letter-spacing: 0.4px;">Cheese types</h4>
                                    <div class="flex flex-wrap gap-3 text-[12px]">
                                        <div class="flex items-center"><div class="w-3 h-3 bg-amber-400 rounded mr-1"></div> Farmhouse</div>
                                        <div class="flex items-center"><div class="w-3 h-3 bg-green-400 rounded mr-1"></div> Garlic & Basil</div>
                                        <div class="flex items-center"><div class="w-3 h-3 bg-red-400 rounded mr-1"></div> Tomato & Herb</div>
                                        <div class="flex items-center"><div class="w-3 h-3 bg-yellow-400 rounded mr-1"></div> Cumin Seed</div>
                                        <div class="flex items-center"><div class="w-3 h-3 bg-purple-400 rounded mr-1"></div> Mature</div>
                                    </div>
                                </div>
                                <div>
                                    <h4 class="text-[11.5px] font-medium uppercase mb-2" style="color: var(--muted); letter-spacing: 0.4px;">Symbols</h4>
                                    <div class="text-[12px] space-y-0.5 font-mono">
                                        <div><span>▶</span> <span class="font-sans">Production start</span></div>
                                        <div><span>■</span> <span class="font-sans">Ready date</span></div>
                                        <div><span>●</span> <span class="font-sans">~10 units each</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-2">
                                <label for="timeline-sort" class="text-[13px]" style="color: var(--ink-2);">Sort:</label>
                                <select id="timeline-sort" name="sort" class="mf-select" onchange="updateTimelineSort()" style="width: auto;">
                                    <option value="ready_date" {{ request('sort', 'ready_date') === 'ready_date' ? 'selected' : '' }}>Ready date</option>
                                    <option value="cheese_type" {{ request('sort') === 'cheese_type' ? 'selected' : '' }}>Cheese type</option>
                                    <option value="batch_code" {{ request('sort') === 'batch_code' ? 'selected' : '' }}>Batch code</option>
                                    <option value="quantity" {{ request('sort') === 'quantity' ? 'selected' : '' }}>Quantity</option>
                                </select>
                            </div>
                            <div class="text-[12px]" style="color: var(--muted);">Showing next {{ count($maturingTimeline['columns']) }} weeks</div>
                        </div>

                        @if(isset($maturingTimeline) && count($maturingTimeline['batches']) > 0)
                            <div class="rounded-md overflow-hidden" style="border: 1px solid var(--line);">
                                <div class="sticky top-0 z-10 overflow-x-auto" style="background: var(--bg);">
                                    <div class="flex min-w-max" style="border-bottom: 1px solid var(--line);">
                                        <div class="w-32 sm:w-40 flex-shrink-0 p-3" style="border-right: 1px solid var(--line); background: var(--line-2);">
                                            <div class="text-[12.5px] font-semibold">Batch code</div>
                                        </div>
                                        @php
                                            $maxColumns = 12;
                                            $visibleColumns = min($maxColumns, count($maturingTimeline['columns']));
                                        @endphp
                                        @foreach($maturingTimeline['month_spans'] as $monthSpan)
                                            @if($monthSpan['start_index'] < $visibleColumns)
                                                @php $spanWidth = min($monthSpan['week_count'], $visibleColumns - $monthSpan['start_index']); @endphp
                                                <div class="flex-shrink-0 p-2 text-center" style="border-right: 1px solid var(--line); background: var(--info-soft); width: {{ $spanWidth * 64 }}px;">
                                                    <div class="text-[12.5px] font-semibold" style="color: var(--info);">{{ $monthSpan['month'] }}</div>
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>

                                    <div class="flex min-w-max">
                                        <div class="w-32 sm:w-40 flex-shrink-0" style="border-right: 1px solid var(--line); background: var(--line-2);"></div>
                                        @foreach($maturingTimeline['columns'] as $index => $column)
                                            @if($index < $visibleColumns)
                                                <div class="w-16 sm:w-20 flex-shrink-0 p-1 text-center" style="border-right: 1px solid var(--line); background: var(--bg);">
                                                    <div class="text-[11px]" style="color: var(--muted);">{{ $column['week_label'] }}</div>
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>

                                <div class="max-h-96 overflow-y-auto overflow-x-auto">
                                    @php $lastCheeseType = null; @endphp
                                    @foreach($maturingTimeline['batches'] as $batch)
                                        @if(request('sort') === 'cheese_type' && $lastCheeseType !== $batch['cheese_type'])
                                            @php $lastCheeseType = $batch['cheese_type']; @endphp
                                            <div class="flex min-w-max" style="background: var(--bg); border-bottom: 2px solid var(--line);">
                                                <div class="w-32 sm:w-40 flex-shrink-0 p-2" style="border-right: 1px solid var(--line-2);">
                                                    <div class="text-[12.5px] font-semibold capitalize">{{ str_replace('_', ' ', $batch['cheese_type']) }}</div>
                                                </div>
                                                @foreach($maturingTimeline['columns'] as $index => $column)
                                                    @if($index < $visibleColumns)
                                                        <div class="w-16 sm:w-20 flex-shrink-0" style="border-right: 1px solid var(--line-2);"></div>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                        <div class="flex min-w-max" style="border-bottom: 1px solid var(--line-2);">
                                            <div class="w-32 sm:w-40 flex-shrink-0 p-3" style="border-right: 1px solid var(--line-2);">
                                                <div class="space-y-1">
                                                    @php
                                                        $colorClass = match($batch['cheese_type']) {
                                                            'farmhouse' => 'text-amber-700 bg-amber-50 border-amber-200',
                                                            'garlic_basil' => 'text-green-700 bg-green-50 border-green-200',
                                                            'tomato_herb' => 'text-red-700 bg-red-50 border-red-200',
                                                            'cumin_seed' => 'text-yellow-700 bg-yellow-50 border-yellow-200',
                                                            'mature' => 'text-purple-700 bg-purple-50 border-purple-200',
                                                            default => 'text-gray-700 bg-gray-50 border-gray-200'
                                                        };
                                                    @endphp
                                                    <div class="font-mono text-[11.5px] font-semibold {{ $colorClass }} px-1 py-0.5 rounded border">
                                                        <a href="{{ route('batches.show', $batch['item']->batch) }}" class="hover:underline">
                                                            {{ $batch['batch_code'] }}
                                                        </a>
                                                    </div>
                                                    <div class="flex items-center space-x-0.5">
                                                        @for($i = 0; $i < min($batch['quantity_indicators'], 5); $i++)
                                                            <span class="text-[11px]">●</span>
                                                        @endfor
                                                        @if($batch['quantity_indicators'] > 5)
                                                            <span class="text-[11px]">+</span>
                                                        @endif
                                                        <span class="text-[11px] ml-1 font-mono" style="color: var(--muted);">{{ $batch['quantity'] }}</span>
                                                    </div>
                                                    @if($batch['urgency'] === 'urgent')
                                                        <div class="text-[11px] font-bold" style="color: var(--danger);">{{ $batch['days_until_ready'] }}d!</div>
                                                    @elseif($batch['urgency'] === 'soon')
                                                        <div class="text-[11px] font-semibold" style="color: var(--warn-ink);">{{ $batch['days_until_ready'] }}d</div>
                                                    @endif
                                                </div>
                                            </div>

                                            @foreach($maturingTimeline['columns'] as $index => $column)
                                                @if($index < $visibleColumns)
                                                    <div class="w-16 sm:w-20 flex-shrink-0 p-1 text-center {{ $column['is_new_month'] ? '' : '' }}"
                                                         style="border-right: 1px solid var(--line-2); {{ $column['is_new_month'] ? 'background: var(--info-soft);' : '' }}">
                                                        @if($batch['production_week_column'] === $index)
                                                            <span class="font-mono text-[14px]" style="color: var(--accent-ink);">▶</span>
                                                        @endif
                                                        @if($batch['ready_week_column'] === $index)
                                                            <span class="font-mono text-[14px]" style="color: {{ $batch['urgency'] === 'urgent' ? 'var(--danger)' : ($batch['urgency'] === 'soon' ? 'var(--warn-ink)' : 'var(--info)') }};">■</span>
                                                        @endif
                                                        @if($batch['production_week_column'] !== null && $batch['ready_week_column'] !== null &&
                                                            $index >= $batch['production_week_column'] && $index <= $batch['ready_week_column'])
                                                            <div class="w-full h-1 rounded mt-1" style="background: var(--line);">
                                                                @php
                                                                    $weekProgress = 0;
                                                                    if ($index < $batch['ready_week_column']) {
                                                                        $weekProgress = 100;
                                                                    } elseif ($index === $batch['ready_week_column']) {
                                                                        $weekProgress = $batch['progress'];
                                                                    }
                                                                @endphp
                                                                <div class="h-1 rounded" style="background: var(--info); width: {{ $weekProgress }}%"></div>
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <div class="text-center py-8" style="color: var(--muted);">No maturing stock scheduled.</div>
                        @endif
                    </div>

                    <div id="calendar-view" class="hidden space-y-4">
                        @forelse($maturingCalendar as $monthKey => $monthData)
                            <div class="rounded-md overflow-hidden" style="border: 1px solid var(--line);">
                                <div class="px-4 py-2" style="background: var(--bg); border-bottom: 1px solid var(--line);">
                                    <h4 class="text-[14px] font-semibold">{{ $monthData['meta']['month_name'] }}</h4>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-1 p-3">
                                    @for($week = 1; $week <= 4; $week++)
                                        @php $weekKey = "week_$week"; @endphp
                                        <div class="rounded p-2 min-h-[120px]" style="border: 1px solid var(--line-2);">
                                            <h5 class="text-[12px] font-medium mb-2" style="color: var(--muted);">Week {{ $week }}</h5>
                                            @if(isset($monthData['weeks'][$weekKey]['by_type']))
                                                @foreach($monthData['weeks'][$weekKey]['by_type'] as $cheeseType => $items)
                                                    @php
                                                        $colorClass = match($cheeseType) {
                                                            'farmhouse' => 'bg-amber-100 text-amber-800 border-amber-200',
                                                            'garlic_basil' => 'bg-green-100 text-green-800 border-green-200',
                                                            'tomato_herb' => 'bg-red-100 text-red-800 border-red-200',
                                                            'cumin_seed' => 'bg-yellow-100 text-yellow-800 border-yellow-200',
                                                            'mature' => 'bg-purple-100 text-purple-800 border-purple-200',
                                                            default => 'bg-gray-100 text-gray-800 border-gray-200'
                                                        };
                                                    @endphp
                                                    @foreach($items as $itemData)
                                                        <div class="border {{ $colorClass }} rounded px-2 py-1 mb-1 text-[11.5px]">
                                                            <div class="font-medium">
                                                                <a href="{{ route('batches.show', $itemData['item']->batch) }}" class="hover:underline">{{ $itemData['batch_code'] }}</a>
                                                            </div>
                                                            <div class="opacity-75">{{ number_format($itemData['item']->quantity_remaining) }} units</div>
                                                            <div class="w-full bg-gray-200 rounded-full h-1 mt-1">
                                                                <div class="bg-current h-1 rounded-full" style="width: {{ $itemData['progress'] }}%"></div>
                                                            </div>
                                                            <div class="mt-1 {{ $itemData['urgency'] === 'urgent' ? 'text-red-600 font-bold' : ($itemData['urgency'] === 'soon' ? 'text-orange-600 font-semibold' : '') }}">
                                                                @if($itemData['days_until_ready'] >= 0)
                                                                    {{ $itemData['days_until_ready'] }}d left
                                                                @else
                                                                    Ready!
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                @endforeach
                                            @else
                                                <div class="text-[11.5px] italic" style="color: var(--faint);">No items</div>
                                            @endif
                                        </div>
                                    @endfor
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-8" style="color: var(--muted);">No maturing stock scheduled.</div>
                        @endforelse
                    </div>

                    <div id="table-view" class="hidden overflow-x-auto">
                        <table class="w-full border-collapse text-[13px]">
                            <thead>
                                <tr>
                                    <th class="mf-th">Product</th>
                                    <th class="mf-th">Batch</th>
                                    <th class="mf-th">Variant</th>
                                    <th class="mf-th text-right">Stock</th>
                                    <th class="mf-th">Progress</th>
                                    <th class="mf-th text-right">Unit price</th>
                                    <th class="mf-th text-right">Future value</th>
                                    <th class="mf-th">Ready date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($maturing as $item)
                                    @php
                                        $typeTone = match($item->batch->product->type) {
                                            'milk' => 'info',
                                            'yoghurt' => 'accent',
                                            'cheese' => 'warn',
                                            default => 'neutral',
                                        };
                                        $productionDate = $item->batch->production_date;
                                        $readyDate = $item->batch->ready_date;
                                        $now = now();
                                        $totalDays = $productionDate->diffInDays($readyDate);
                                        $daysPassed = $productionDate->diffInDays($now);
                                        $progress = $totalDays > 0 ? min(100, ($daysPassed / $totalDays) * 100) : 100;
                                        $daysUntilReady = (int) $now->diffInDays($readyDate, false);
                                    @endphp
                                    <tr style="border-top: 1px solid var(--line-2);">
                                        <td class="mf-td">
                                            <div class="font-medium">{{ $item->batch->product->name }}</div>
                                            <span class="mf-tag mf-tag-{{ $typeTone }} mt-0.5">{{ ucfirst($item->batch->product->type) }}</span>
                                        </td>
                                        <td class="mf-td font-mono">
                                            <a href="{{ route('batches.show', $item->batch) }}" class="mf-link">{{ $item->batch->batch_code }}</a>
                                        </td>
                                        <td class="mf-td">
                                            <div>{{ $item->productVariant->name }}</div>
                                            <div class="text-[11.5px] mt-0.5" style="color: var(--muted);">{{ $item->productVariant->size }} {{ $item->productVariant->unit }}</div>
                                        </td>
                                        <td class="mf-td font-mono text-right font-medium" style="color: var(--warn-ink);">{{ number_format($item->quantity_remaining) }}</td>
                                        <td class="mf-td">
                                            <div class="w-full rounded-full h-1.5" style="background: var(--line);">
                                                <div class="h-1.5 rounded-full" style="background: var(--warn); width: {{ $progress }}%"></div>
                                            </div>
                                            <div class="text-[11.5px] mt-0.5 font-mono" style="color: var(--muted);">{{ round($progress) }}%</div>
                                        </td>
                                        <td class="mf-td font-mono text-right">{{ $item->productVariant->price_label }}</td>
                                        <td class="mf-td font-mono text-right font-medium">€{{ number_format($item->productVariant->calculatePrice($item->quantity_remaining), 2) }}</td>
                                        <td class="mf-td">
                                            <div class="font-mono" style="color: {{ $daysUntilReady <= 7 ? 'var(--danger)' : 'var(--warn-ink)' }};">
                                                {{ $item->batch->ready_date->format('d/m/Y') }}
                                            </div>
                                            <div class="text-[11.5px]" style="color: var(--muted);">
                                                @if($daysUntilReady >= 0)
                                                    {{ $daysUntilReady }}d left
                                                @else
                                                    Ready!
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <script>
                function switchView(activeView) {
                    document.getElementById('timeline-view').classList.add('hidden');
                    document.getElementById('calendar-view').classList.add('hidden');
                    document.getElementById('table-view').classList.add('hidden');

                    ['timeline-view-btn', 'calendar-view-btn', 'table-view-btn'].forEach(btnId => {
                        const btn = document.getElementById(btnId);
                        btn.classList.remove('mf-btn-primary');
                        btn.classList.add('mf-btn-secondary');
                    });

                    document.getElementById(activeView).classList.remove('hidden');
                    const activeBtn = document.getElementById(activeView + '-btn');
                    activeBtn.classList.remove('mf-btn-secondary');
                    activeBtn.classList.add('mf-btn-primary');
                }

                document.getElementById('timeline-view-btn').addEventListener('click', function() {
                    switchView('timeline-view');
                });
                document.getElementById('calendar-view-btn').addEventListener('click', function() {
                    switchView('calendar-view');
                });
                document.getElementById('table-view-btn').addEventListener('click', function() {
                    switchView('table-view');
                });

                function updateTimelineSort() {
                    const sortValue = document.getElementById('timeline-sort').value;
                    const url = new URL(window.location);
                    url.searchParams.set('sort', sortValue);
                    window.location.href = url.toString();
                }
            </script>
        @endif

        @if($readyToSell->count() === 0 && $maturing->count() === 0)
            <div class="mf-panel p-10 text-center">
                <h3 class="text-[14px] font-semibold">No stock available</h3>
                <p class="mt-1 text-[13px]" style="color: var(--muted);">No stock items match your current filters, or you haven't created any batches yet.</p>
                @can('create', App\Models\Batch::class)
                    <div class="mt-4">
                        <a href="{{ route('batches.create') }}" class="mf-btn-primary">Create first batch</a>
                    </div>
                @endcan
            </div>
        @endif
    </div>
</x-app-layout>
