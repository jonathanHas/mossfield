<x-picking-layout>
    @php
        $items = $order->orderItems;
        $linesDone = $items->filter(fn ($item) => $item->isFullyFulfilled())->count();
        $linesShort = $items->filter(fn ($item) => $item->isShortClosed())->count();
        $shortUnits = (int) $items->sum('quantity_short');
        $linesTotal = $items->count();
        // Short lines are resolved, not outstanding — otherwise the bar sticks
        // below 100% on an order that's actually finished.
        $pct = $linesTotal > 0 ? round(($linesDone + $linesShort) / $linesTotal * 100) : 0;
        $isReady = $order->isPickingComplete();
        $totalWeight = (float) $items->sum('weight_fulfilled_kg');
        $deliverySub = $order->delivery_date
            ? ($order->delivery_date->isToday() ? 'today' : $order->delivery_date->format('D j M'))
            : null;
    @endphp

    @if ($isReady)
        {{-- ── Order ready · celebration / handoff ── --}}
        {{-- Full-viewport wash behind the content (the shell itself keeps the
             cream bg). Amber, not green, when anything went short — the screen
             must not celebrate an order the customer is missing items from. --}}
        @php $wash = $linesShort > 0 ? 'var(--warn-soft)' : 'var(--accent-soft)'; @endphp
        <div style="position: fixed; inset: 0; background: {{ $wash }}; z-index: -1;"></div>
        <div>
            <div class="mob-head" style="background: {{ $wash }}; border-bottom: 0;">
                <a href="{{ route('picking.index') }}" class="iconbtn" style="background: rgba(255,255,255,0.6); width: 34px; height: 34px; border-radius: 50%; border: 1px solid var(--line); display: grid; place-items: center; color: var(--ink-2);">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                </a>
            </div>

            @php
                $tone = $linesShort > 0 ? 'var(--warn)' : 'var(--accent)';
                $toneInk = $linesShort > 0 ? 'var(--warn-ink)' : 'var(--accent-ink)';
            @endphp
            <div style="padding: 20px 22px 0; text-align: center;">
                <div style="width: 76px; height: 76px; border-radius: 38px; background: {{ $tone }}; color: #fff; display: grid; place-items: center; margin: 12px auto 0; box-shadow: 0 8px 24px -8px color-mix(in oklab, {{ $tone }} 60%, transparent);">
                    @if ($linesShort > 0)
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                    @else
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                    @endif
                </div>
                <h1 style="font-family: Fraunces, Georgia, serif; font-size: 28px; font-weight: 500; letter-spacing: -0.6px; margin: 16px 0 0; color: {{ $toneInk }};">
                    {{ $linesShort > 0 ? 'Order closed — short.' : 'Order ready.' }}
                </h1>
                <div style="font-size: 14px; color: {{ $toneInk }}; opacity: 0.75; margin-top: 6px;">
                    @if ($linesShort > 0)
                        {{ $linesDone }} of {{ $linesTotal }} {{ Str::plural('line', $linesTotal) }} picked ·
                        {{ $linesShort }} short ({{ $shortUnits }} {{ Str::plural('unit', $shortUnits) }})
                    @else
                        {{ $linesTotal }} {{ Str::plural('line', $linesTotal) }} picked{{ $totalWeight > 0 ? ' · '.number_format($totalWeight, 1).' kg total' : '' }}
                    @endif
                </div>
            </div>

            <div style="padding: 22px 18px 0;">
                @if ($linesShort > 0)
                    {{-- Each short line is one tap from being reopened, so a
                         mis-tap on the floor is cheap to undo. --}}
                    <div class="mob-card" style="background: var(--panel); margin-bottom: 10px;">
                        <div class="mf-eyebrow">Not supplied</div>
                        @foreach ($items->filter(fn ($item) => $item->isShortClosed()) as $shortItem)
                            <a href="{{ route('picking.item', [$order, $shortItem]) }}" class="flex items-center justify-between gap-3 no-underline"
                               style="color: var(--ink); margin-top: 8px;">
                                <div class="min-w-0">
                                    <div style="font-size: 14px; font-weight: 500;">{{ $shortItem->productVariant->product->name }}</div>
                                    <div style="font-size: 12px; color: var(--muted);">{{ $shortItem->productVariant->name }}</div>
                                </div>
                                <span class="mf-tag mf-tag-warn">Short {{ $shortItem->quantity_short }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
                <div class="mob-card" style="background: var(--panel);">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div style="font-size: 16px; font-weight: 500;">{{ $order->customer->name }}</div>
                            <div class="font-mono" style="font-size: 11.5px; color: var(--muted); margin-top: 2px;">{{ $order->order_number }}</div>
                        </div>
                        @can('see-financials')
                            <span class="font-mono" style="font-size: 14px; font-weight: 500;">€{{ number_format($order->total_amount, 2) }}</span>
                        @endcan
                    </div>
                    <div style="height: 1px; background: var(--line-2); margin: 12px 0;"></div>
                    <div class="grid grid-cols-2 gap-y-2.5" style="font-size: 13px;">
                        <div style="color: var(--muted);">Delivery</div>
                        <div class="text-right">{{ $deliverySub ?? '—' }}</div>
                        <div style="color: var(--muted);">Status</div>
                        <div class="text-right">
                            @if ($linesShort > 0)
                                <span class="mf-tag mf-tag-warn">Ready · short</span>
                            @else
                                <span class="mf-tag mf-tag-accent">Ready</span>
                            @endif
                        </div>
                        @can('see-financials')
                            <div style="color: var(--muted);">Payment</div>
                            <div class="text-right"><span class="mf-tag mf-tag-{{ $order->payment_status === 'paid' ? 'accent' : 'warn' }}">{{ ucfirst($order->payment_status) }}</span></div>
                        @endcan
                    </div>
                </div>

                @if ($nextOrder)
                    <a href="{{ route('picking.show', $nextOrder) }}" class="mob-card no-underline block" style="background: var(--panel); margin-top: 10px; color: var(--ink);">
                        <div class="mf-eyebrow">Next up</div>
                        <div class="flex items-center gap-3 mt-2">
                            <div style="width: 38px; height: 38px; border-radius: 19px; background: var(--info-soft); display: grid; place-items: center; color: var(--info); flex-shrink: 0;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16V8l-8-4-8 4v8l8 4 8-4z"/></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div style="font-size: 14px; font-weight: 500;">{{ $nextOrder->customer->name }}</div>
                                <div style="font-size: 12px; color: var(--muted);">
                                    {{ (int) $nextOrder->orderItems->sum('quantity_ordered') }} items{{ $nextOrder->delivery_date ? ' · '.($nextOrder->delivery_date->isToday() ? 'today' : $nextOrder->delivery_date->format('D j M')) : '' }}
                                    @if ($nextOrder->isDeliveryOverdue())
                                        <span class="mf-tag mf-tag-warn">Past its delivery date</span>
                                    @endif
                                </div>
                            </div>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        </div>
                    </a>
                @endif
            </div>
        </div>

        <div class="mob-footer" style="background: linear-gradient(180deg, rgba(255,255,255,0) 0%, var(--accent-soft) 30%);">
            <a href="{{ route('picking.index') }}" class="mob-fab ghost">Back to queue</a>
            @if ($nextOrder)
                <a href="{{ route('picking.show', $nextOrder) }}" class="mob-fab">
                    Next order →
                </a>
            @endif
        </div>
    @else
        {{-- ── Picking overview · line-by-line state ── --}}
        <div class="mob-head">
            <a href="{{ route('picking.index') }}" class="iconbtn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            </a>
            <div class="flex-1 min-w-0">
                <h1 class="title truncate">{{ $order->customer->name }}</h1>
                <div class="sub">{{ $order->order_number }}{{ $deliverySub ? ' · '.$deliverySub : '' }}</div>
            </div>
        </div>

        {{-- Progress band --}}
        <div style="padding: 14px 18px 6px;">
            <div class="flex items-center justify-between gap-3">
                <div class="mf-eyebrow">{{ $linesDone }} of {{ $linesTotal }} lines picked</div>
                <div class="font-mono" style="font-size: 11.5px; color: var(--muted);">{{ $pct }}%</div>
            </div>
            <div class="mf-bar" style="margin-top: 8px; height: 4px;"><i style="width: {{ $pct }}%"></i></div>
        </div>

        {{-- Line list --}}
        <div class="mob-section" style="padding-top: 14px;">
            <div class="mob-tap-list">
                @foreach ($items as $i => $item)
                    @php
                        $done = $item->isFullyFulfilled();
                        // Three states, not two: a short line is closed, not
                        // picked — it must not wear the green tick.
                        $short = $item->isShortClosed();
                        $current = $nextItem && $item->id === $nextItem->id;
                        $firstAlloc = $item->orderAllocations->first();
                        $qtyLabel = $item->isVariableWeight() && $item->weight_fulfilled_kg > 0
                            ? number_format($item->weight_fulfilled_kg, 2).' kg'
                            : "{$item->quantity_fulfilled} / {$item->quantity_ordered}";
                    @endphp
                    <a href="{{ route('picking.item', [$order, $item]) }}" class="mob-tap-row"
                       style="{{ $current ? 'background: color-mix(in oklab, var(--accent-soft) 40%, var(--panel)); border-left: 3px solid var(--accent); padding-left: 15px;' : 'border-left: 3px solid transparent; padding-left: 15px;' }}">
                        <div class="font-mono" style="width: 28px; height: 28px; border-radius: 14px; flex-shrink: 0; display: grid; place-items: center; font-size: 11px;
                            background: {{ $short ? 'var(--warn)' : ($done ? 'var(--accent)' : ($current ? 'var(--panel)' : 'var(--line-2)')) }};
                            border: {{ $current ? '2px solid var(--accent)' : '0' }};
                            color: {{ ($done || $short) ? '#fff' : 'var(--muted)' }};">
                            @if ($short)
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/></svg>
                            @elseif ($done)
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                            @else
                                {{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <div style="font-size: 15px; font-weight: 500; color: {{ $short ? 'var(--warn-ink)' : ($done ? 'var(--muted)' : 'var(--ink)') }}; {{ $done ? 'text-decoration: line-through;' : '' }}">
                                {{ $item->productVariant->product->name }}
                            </div>
                            <div style="font-size: 12px; color: var(--muted); margin-top: 2px;">
                                {{ $item->productVariant->name }}@if ($firstAlloc) · <span class="font-mono">{{ $firstAlloc->batchItem->batch->batch_code }}</span> @endif
                            </div>
                        </div>
                        <div class="font-mono text-right" style="font-size: 13px; font-weight: 500; color: {{ $short ? 'var(--warn-ink)' : ($done ? 'var(--accent-ink)' : 'var(--ink)') }};">
                            {{ $qtyLabel }}
                            @if ($short)
                                <div style="font-size: 10px; margin-top: 1px; font-weight: 400;">short {{ $item->quantity_short }}</div>
                            @elseif ($item->isVariableWeight())
                                <div style="font-size: 10px; color: var(--muted); margin-top: 1px; font-weight: 400;">variable</div>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Next-up hint --}}
        @if ($nextItem)
            <div class="mob-section" style="margin-top: 18px;">
                <div class="mf-flash mf-flash-info" style="margin-bottom: 0; font-size: 12.5px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><path d="M12 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/></svg>
                    <div>
                        <b>{{ $nextItem->productVariant->product->name }}</b> is next.
                        @if ($suggestedBatch)
                            <br>From batch <span class="font-mono">{{ $suggestedBatch['batchItem']->batch->batch_code }}</span> · {{ $suggestedBatch['max'] }} in stock
                        @else
                            <br><span style="color: var(--warn-ink);">No available stock for this line — open it to mark it short.</span>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if ($nextItem)
            <div class="mob-footer">
                <a href="{{ route('picking.item', [$order, $nextItem]) }}" class="mob-fab accent">
                    Pick {{ $nextItem->productVariant->name }} →
                </a>
            </div>
        @endif
    @endif
</x-picking-layout>
