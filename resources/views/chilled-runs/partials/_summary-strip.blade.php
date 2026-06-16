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
    </div>
</div>
