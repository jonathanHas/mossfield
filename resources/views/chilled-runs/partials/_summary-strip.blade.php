{{-- Crate / load summary strip --}}
<div class="mf-panel mb-4">
    <div class="mf-crate-strip">
        <div class="mf-crate-cell">
            <div class="mf-eyebrow">Stops</div>
            <div class="v">{{ $sheet['rows']->count() }}</div>
        </div>
        <div class="mf-crate-cell">
            <div class="mf-eyebrow">Milk units</div>
            <div class="v">{{ $sheet['milkUnits'] }}<small> &middot; {{ $sheet['milkCrates'] }} {{ Str::plural('crate', $sheet['milkCrates']) }}</small></div>
        </div>
        <div class="mf-crate-cell">
            <div class="mf-eyebrow">Yoghurt units</div>
            <div class="v">{{ $sheet['yogUnits'] }}<small> &middot; {{ $sheet['yogCrates'] }} {{ Str::plural('crate', $sheet['yogCrates']) }}</small></div>
        </div>
        @if ($sheet['cheeseUnits'] > 0)
            <div class="mf-crate-cell">
                <div class="mf-eyebrow">Cheese units</div>
                <div class="v">{{ $sheet['cheeseUnits'] }}</div>
            </div>
        @endif
        <div class="mf-crate-cell" style="flex: 1.4;">
            <div class="flex items-center justify-between gap-3">
                <span class="mf-eyebrow">Allocated</span>
                <span class="font-mono text-[12px] font-medium" style="color: {{ $sheet['allocatedPct'] === 100 && $sheet['allocStopsTotal'] > 0 ? 'var(--accent-ink)' : 'var(--ink)' }};">
                    {{ $sheet['allocatedStops'] }}/{{ $sheet['allocStopsTotal'] }}
                </span>
            </div>
            <div class="mf-bar" style="margin-top: 10px;">
                <i style="width: {{ $sheet['allocatedPct'] }}%;"></i>
            </div>
            <div class="font-mono" style="font-size: 10.5px; color: var(--muted); margin-top: 6px;">
                @if ($sheet['allocStopsTotal'] === 0)
                    No orders to allocate this day
                @elseif ($sheet['allocatedPct'] === 100)
                    All stops covered &mdash; ready to pick
                @else
                    {{ $sheet['allocatedUnits'] }} of {{ $sheet['orderedUnits'] }} units reserved
                @endif
            </div>
        </div>
        <div class="mf-crate-cell" style="flex: 1.4;">
            <div class="flex items-center justify-between gap-3">
                <span class="mf-eyebrow">Loaded</span>
                <span class="font-mono text-[12px] font-medium" style="color: {{ $sheet['loadedPct'] === 100 && $sheet['loadableCount'] > 0 ? 'var(--accent-ink)' : 'var(--ink)' }};">
                    {{ $sheet['loadedCount'] }}/{{ $sheet['loadableCount'] }}
                </span>
            </div>
            <div class="mf-bar" style="margin-top: 10px;">
                <i style="width: {{ $sheet['loadedPct'] }}%;"></i>
            </div>
            <div class="font-mono" style="font-size: 10.5px; color: var(--muted); margin-top: 6px;">
                @if ($sheet['loadableCount'] === 0)
                    No orders to load this day
                @elseif ($sheet['loadedPct'] === 100)
                    All stops loaded &mdash; ready to roll
                @else
                    {{ $sheet['loadableCount'] - $sheet['loadedCount'] }} {{ Str::plural('stop', $sheet['loadableCount'] - $sheet['loadedCount']) }} still to load
                @endif
            </div>
        </div>
        <div class="mf-crate-cell" style="flex: 1.4;">
            <div class="flex items-center justify-between gap-3">
                <span class="mf-eyebrow">Dispatched</span>
                <span class="font-mono text-[12px] font-medium" style="color: {{ $sheet['dispatchedPct'] === 100 && $sheet['loadableCount'] > 0 ? 'var(--accent-ink)' : 'var(--ink)' }};">
                    {{ $sheet['dispatchedCount'] }}/{{ $sheet['loadableCount'] }}
                </span>
            </div>
            <div class="mf-bar" style="margin-top: 10px;">
                <i style="width: {{ $sheet['dispatchedPct'] }}%;"></i>
            </div>
            <div class="font-mono" style="font-size: 10.5px; color: var(--muted); margin-top: 6px;">
                @if ($sheet['loadableCount'] === 0)
                    No orders this day
                @elseif ($sheet['dispatchedPct'] === 100)
                    All stops dispatched
                @else
                    {{ $sheet['loadableCount'] - $sheet['dispatchedCount'] }} {{ Str::plural('stop', $sheet['loadableCount'] - $sheet['dispatchedCount']) }} still to dispatch
                @endif
            </div>
        </div>
    </div>
</div>
