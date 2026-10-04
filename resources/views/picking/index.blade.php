<x-picking-layout>
    @php
        // Classify each order for the queue row: ready (done), picking
        // (started), todo (untouched). Mirrors the design's three states.
        $classified = $orders->map(function ($order) {
            $picked = (int) $order->orderItems->sum('quantity_fulfilled');
            $total = (int) $order->orderItems->sum('quantity_ordered');
            $state = $order->status === 'ready' ? 'ready' : ($picked > 0 ? 'picking' : 'todo');

            return compact('order', 'picked', 'total', 'state');
        });

        $readyCount = $classified->where('state', 'ready')->count();
        $pickingCount = $classified->where('state', 'picking')->count();
        $todoCount = $classified->where('state', 'todo')->count();

        $continueTo = $classified->first(fn ($row) => $row['state'] === 'picking')
            ?? $classified->first(fn ($row) => $row['state'] === 'todo');

        $pct = $totalUnits > 0 ? round($pickedUnits / $totalUnits * 100) : 0;

        // Label for a queue day key (Y-m-d, or 'none' for undated orders).
        $dayLabel = function (?string $day) {
            if ($day === null) {
                return 'Today';
            }
            if ($day === 'none') {
                return 'No delivery date';
            }
            $date = \Illuminate\Support\Carbon::parse($day);
            if ($date->isToday()) {
                return 'Today';
            }
            if ($date->isTomorrow()) {
                return 'Tomorrow';
            }

            return $date->format('D j M');
        };
    @endphp

    <div style="padding: 18px 18px 12px;" class="flex items-start justify-between gap-3">
        <div>
            <div class="mf-eyebrow">{{ now()->format('l · j M') }}</div>
            {{-- One delivery day at a time (F-30); ◀ ▶ step through the days with work. --}}
            <div class="flex items-center gap-2" style="margin: 4px 0 0;">
                @if ($prevDay)
                    <a href="{{ route('picking.index', ['date' => $prevDay]) }}" class="mob-day-nav" title="{{ $dayLabel($prevDay) }}" aria-label="Previous day: {{ $dayLabel($prevDay) }}">◀</a>
                @endif
                <h1 class="font-display" style="font-size: 24px; font-weight: 500; letter-spacing: -0.5px; margin: 0; font-family: Fraunces, Georgia, serif;">
                    {{ $dayLabel($selected) }}
                </h1>
                @if ($nextDay)
                    <a href="{{ route('picking.index', ['date' => $nextDay]) }}" class="mob-day-nav" title="{{ $dayLabel($nextDay) }}" aria-label="Next day: {{ $dayLabel($nextDay) }}">▶</a>
                @endif
            </div>
            <div class="flex items-center gap-1.5 mt-2 flex-wrap">
                <span class="mf-tag mf-tag-accent"><span class="inline-block w-[5px] h-[5px] rounded-full" style="background: currentColor;"></span>{{ $readyCount }} ready</span>
                <span class="mf-tag mf-tag-info"><span class="inline-block w-[5px] h-[5px] rounded-full" style="background: currentColor;"></span>{{ $pickingCount }} picking</span>
                <span class="mf-tag mf-tag-warn"><span class="inline-block w-[5px] h-[5px] rounded-full" style="background: currentColor;"></span>{{ $todoCount }} to do</span>
            </div>
        </div>
        <a href="{{ route('dashboard') }}" class="iconbtn" title="Dashboard"
           style="width: 34px; height: 34px; border-radius: 50%; background: var(--panel); border: 1px solid var(--line); display: grid; place-items: center; color: var(--ink-2); flex-shrink: 0;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l9-9 9 9"/><path d="M5 10v10h14V10"/></svg>
        </a>
    </div>

    {{-- Progress strip --}}
    <div style="padding: 0 18px 14px;">
        <div class="mob-card">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <div class="mf-eyebrow">Items picked</div>
                    <div style="font-family: Fraunces, Georgia, serif; font-size: 30px; font-weight: 500; letter-spacing: -1px; line-height: 1; margin-top: 4px;">
                        {{ $pickedUnits }}<span style="color: var(--muted); font-size: 18px;"> / {{ $totalUnits }}</span>
                    </div>
                </div>
                <div class="font-mono" style="font-size: 11px; color: var(--muted);">{{ $pct }}%</div>
            </div>
            <div class="mf-bar" style="margin-top: 10px; height: 4px;"><i style="width: {{ $pct }}%"></i></div>
        </div>
    </div>

    <div class="mob-section-label" style="padding: 0 22px 6px;">Your queue</div>

    @if ($classified->isEmpty())
        <div class="mob-section">
            <div class="mob-card" style="color: var(--muted); font-size: 13.5px;">
                Nothing to pick — no orders are awaiting picking right now.
            </div>
        </div>
    @else
        @php $rowNum = 0; @endphp
            <div style="background: var(--panel); border-top: 1px solid var(--line); border-bottom: 1px solid var(--line);">
                @foreach ($classified->values() as $groupIndex => $row)
                    @php
                        $order = $row['order'];
                        $rowNum++;
                        $tone = ['ready' => 'accent', 'picking' => 'info', 'todo' => 'warn'][$row['state']];
                        $label = match ($row['state']) {
                            'ready' => 'Ready',
                            'picking' => "{$row['picked']}/{$row['total']} picked",
                            default => 'To do',
                        };
                        $weightKg = (float) $order->orderItems->sum('weight_fulfilled_kg');
                    @endphp
                    <a href="{{ route('picking.show', $order) }}" class="flex gap-3.5 no-underline"
                       style="padding: 14px 18px; color: var(--ink); {{ $groupIndex > 0 ? 'border-top: 1px solid var(--line-2);' : '' }} {{ $row['state'] === 'picking' ? 'background: color-mix(in oklab, var(--info-soft) 60%, var(--panel));' : '' }}">
                        <div style="width: 32px; padding-top: 4px; flex-shrink: 0;">
                            @if ($row['state'] === 'ready')
                                <div style="width: 28px; height: 28px; border-radius: 14px; background: var(--accent); color: #fff; display: grid; place-items: center;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                                </div>
                            @elseif ($row['state'] === 'picking')
                                <div style="width: 28px; height: 28px; border-radius: 14px; background: var(--info-soft); color: var(--info); display: grid; place-items: center;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16V8l-8-4-8 4v8l8 4 8-4z"/></svg>
                                </div>
                            @else
                                <div class="font-mono" style="width: 28px; height: 28px; border-radius: 14px; background: var(--line-2); color: var(--muted); display: grid; place-items: center; font-size: 11px;">
                                    {{ str_pad($rowNum, 2, '0', STR_PAD_LEFT) }}
                                </div>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <div style="font-size: 16px; font-weight: 500; letter-spacing: -0.1px;">{{ $order->customer->name }}</div>
                            <div class="font-mono" style="font-size: 11.5px; color: var(--muted); margin-top: 2px;">
                                {{ $order->order_number }} · {{ $row['total'] }} {{ Str::plural('item', $row['total']) }}@if ($weightKg > 0) · {{ number_format($weightKg, 1) }} kg @endif
                            </div>
                            <div class="flex items-center gap-2 mt-2">
                                <span class="mf-tag mf-tag-{{ $tone }}">{{ $label }}</span>
                            </div>
                        </div>
                        <div style="color: var(--faint); align-self: center;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        </div>
                    </a>
                @endforeach
            </div>
    @endif

    {{-- Ready orders from earlier days: the office's to dispatch, not the floor's to pick. --}}
    @if ($stale->isNotEmpty())
        <div class="mob-section" style="padding-top: 14px;">
            <details class="mob-card" data-stale-bucket>
                <summary style="cursor: pointer; font-size: 13.5px; color: var(--ink-2);">
                    Ready, awaiting dispatch — {{ $stale->count() }} {{ Str::plural('order', $stale->count()) }} from earlier days
                </summary>
                <div style="margin-top: 8px;">
                    @foreach ($stale as $order)
                        <a href="{{ route('picking.show', $order) }}" class="block no-underline font-mono"
                           style="padding: 8px 0; font-size: 12px; color: var(--ink-2); {{ $loop->first ? '' : 'border-top: 1px solid var(--line-2);' }}">
                            {{ $order->order_number }} · <span style="font-family: inherit;">{{ $order->customer->name }}</span> · {{ $order->delivery_date->format('j M') }}
                        </a>
                    @endforeach
                </div>
            </details>
        </div>
    @endif

    @if ($continueTo)
        <div class="mob-footer">
            <a href="{{ route('picking.show', $continueTo['order']) }}" class="mob-fab accent">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16V8l-8-4-8 4v8l8 4 8-4z"/></svg>
                Continue picking
            </a>
        </div>
    @endif
</x-picking-layout>
