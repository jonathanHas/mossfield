<x-app-layout>
    <x-slot name="header">Dashboard</x-slot>

    @php
        $hour = (int) now()->format('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

        // Sync freshness — same thresholds the old dashboard used.
        $lastSyncAt = isset($lastOnlineOrderImportAt) ? \Carbon\Carbon::parse($lastOnlineOrderImportAt) : null;
        $ageMinutes = $lastSyncAt ? (int) $lastSyncAt->diffInMinutes(now()) : null;
        $syncState = match (true) {
            $ageMinutes === null => 'never',
            $ageMinutes > 360 => 'danger',
            $ageMinutes > 120 => 'warn',
            default => 'ok',
        };
        $showSyncAlert = $user->hasRole('admin', 'office')
            && config('services.mossorders.ui_enabled')
            && in_array($syncState, ['warn', 'danger', 'never'], true);

        $unallocated = $unallocatedOrders ?? collect();
        $unallocatedN = $unallocatedCount ?? 0;
        $readyN = $readyToShipCount ?? 0;
        $toConfirmN = $toConfirmCount ?? 0;
        $toAllocateN = $toAllocateCount ?? 0;
        $overdueN = $overdueDeliveryCount ?? 0;

        // What the office should do next, one bit per action (each only when > 0).
        $bits = [];
        $actionBits = [];
        if ($user->hasRole('admin', 'office')) {
            if ($toConfirmN > 0) {
                $bits[] = "{$toConfirmN} ".Str::plural('order', $toConfirmN).' to confirm';
                $actionBits[] = "{$toConfirmN} to confirm";
            }
            if ($toAllocateN > 0) {
                $bits[] = "{$toAllocateN} ".Str::plural('order', $toAllocateN).' to allocate';
                $actionBits[] = "{$toAllocateN} to allocate";
            }
            if ($overdueN > 0) {
                $bits[] = "{$overdueN} past ".($overdueN === 1 ? 'its' : 'their').' delivery date';
            }
        }
        $summary = $bits ? 'To do: '.implode(' · ', $bits).'.' : 'Everything is on track.';
        // Separate state, separate sentence.
        if ($showSyncAlert) {
            $summary .= ' Online sync is behind.';
        }
    @endphp

    {{-- Driver gets a placeholder only. --}}
    @if ($user->isDriver())
        <div class="px-6 py-8 max-w-3xl">
            <div class="mf-panel p-6" style="background: var(--panel);">
                <div class="text-[12px] font-mono" style="color: var(--muted);">{{ now()->format('l, j F') }}</div>
                <div class="mt-1 text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">
                    {{ $greeting }}, {{ $user->name }}.
                </div>
                <p class="mt-3 text-[13.5px]" style="color: var(--ink-2); line-height: 1.6;">
                    The run sheet hasn't been opened to drivers yet. The office has your stops for today.
                </p>
            </div>
        </div>
    @else
        <div class="px-6 py-5" style="background: var(--bg);">
            {{-- Hero band --}}
            <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-3 mb-4">
                <div>
                    <div class="text-[12px] font-mono" style="color: var(--muted);">
                        {{ now()->format('l, j F') }}
                    </div>
                    <div class="mt-0.5 text-[28px] font-display font-medium" style="letter-spacing: -0.4px;">
                        {{ $greeting }}, {{ $user->name }}.
                    </div>
                    <div class="mt-0.5 text-[13.5px]" style="color: var(--muted);">
                        {{ $summary }}
                    </div>
                </div>
                @if ($user->hasRole('admin', 'office'))
                    <div class="flex gap-2">
                        @if (config('services.mossorders.ui_enabled'))
                            <a href="{{ route('online-orders.index') }}" class="mf-btn-secondary">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M23 4v6h-6" /><path d="M1 20v-6h6" /><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" />
                                </svg>
                                Online orders
                            </a>
                        @endif
                        <a href="{{ route('order-allocations.index') }}" class="mf-btn-primary">
                            Allocate queue
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 12h14M13 6l6 6-6 6" />
                            </svg>
                        </a>
                    </div>
                @endif
            </div>

            {{-- Sync alert strip (office/admin only, only when stale) --}}
            @if ($showSyncAlert)
                <div
                    class="flex items-center gap-3 px-3.5 py-2.5 rounded-lg mb-4"
                    style="background: var(--warn-soft); border: 1px solid var(--warn-soft-border); color: var(--warn-ink);"
                >
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                        <path d="M12 9v4" /><path d="M12 17h.01" />
                    </svg>
                    <div class="flex-1 text-[13px]">
                        <b>Online order sync</b>
                        @if ($lastSyncAt)
                            last completed {{ $lastSyncAt->diffForHumans() }} ({{ $lastSyncAt->toDayDateTimeString() }}).
                        @else
                            has not completed successfully yet.
                        @endif
                    </div>
                    <a href="{{ route('online-orders.index') }}" class="text-[12px] font-medium" style="color: var(--warn-ink);">View log →</a>
                </div>
            @endif

            {{-- Finance queues. Office/admin only, and only when something needs
                 doing — an empty row of zeroes is just noise on the dashboard. --}}
            @if ($user->hasRole('admin', 'office') && isset($invoicing)
                && ($invoicing['uninvoiced'] || $invoicing['not_emailed'] || $invoicing['overdue_count']))
                <div class="grid gap-3 mb-4 grid-cols-1 sm:grid-cols-3">
                    <a href="{{ route('invoicing.uninvoiced.index') }}">
                        <x-dash-kpi label="Awaiting invoice" :value="$invoicing['uninvoiced']"
                            :sub="$invoicing['uninvoiced'] ? 'to bill' : 'clear'"
                            :tone="$invoicing['uninvoiced'] ? 'warn' : null" />
                    </a>
                    <a href="{{ route('invoicing.invoices.index', ['emailed' => 0]) }}">
                        <x-dash-kpi label="Invoices not emailed" :value="$invoicing['not_emailed']"
                            :sub="$invoicing['not_emailed'] ? 'to send' : 'clear'"
                            :tone="$invoicing['not_emailed'] ? 'warn' : null" />
                    </a>
                    <a href="{{ route('invoicing.overdue.index') }}">
                        <x-dash-kpi label="Overdue" :value="'€'.number_format($invoicing['overdue_total'], 2)"
                            :sub="$invoicing['overdue_count'] ? $invoicing['overdue_count'].' invoices' : 'none'"
                            :tone="$invoicing['overdue_count'] ? 'danger' : null" />
                    </a>
                </div>
            @endif

            {{-- Expired stock: active batches past expiry that still hold units.
                 Unsellable but uncounted anywhere else, so it gets its own tile. --}}
            @if (($expiredCount ?? 0) > 0)
                <div class="grid gap-3 mb-4 grid-cols-1 sm:grid-cols-3">
                    <a href="{{ route('batches.index', ['status' => 'expired']) }}">
                        <x-dash-kpi label="Expired stock" :value="$expiredCount"
                            :sub="number_format($expiredUnits).' units'.($user->can('create', \App\Models\StockWriteOff::class) ? ' · write off' : '')"
                            tone="danger" />
                    </a>
                </div>
            @endif

            {{-- KPI row --}}
            <div class="grid gap-3 mb-4 grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                @if ($user->hasRole('admin', 'office'))
                    <x-dash-kpi label="Orders to action" :value="$toConfirmN + $toAllocateN" :sub="$actionBits ? implode(' · ', $actionBits) : 'clear'" :tone="($toConfirmN + $toAllocateN) ? 'warn' : null" />
                    <x-dash-kpi label="Ready" :value="$readyN" sub="to dispatch" />
                @endif
                <x-dash-kpi
                    label="Batches maturing"
                    :value="$maturingCount ?? 0"
                    :sub="($readyNowCount ?? 0) > 0 ? ($readyNowCount.' ready now') : 'on track'"
                />
                <x-dash-kpi
                    label="Active products"
                    :value="$activeProducts ?? 0"
                    :sub="'of '.($totalProducts ?? 0)"
                />
                @if ($user->hasRole('admin', 'office'))
                    <x-dash-kpi
                        label="Customers"
                        :value="$activeCustomers ?? 0"
                        :sub="'of '.($totalCustomers ?? 0)"
                    />
                @endif
            </div>

            {{-- Main grid: queue | side rail — side by side only from 2xl, so at a
                 1280 px laptop the queue gets the full width (UX review B9). --}}
            <div class="grid gap-4 grid-cols-1 2xl:grid-cols-[minmax(0,1fr)_360px]">
                {{-- Order queue (office/admin only) --}}
                @if ($user->hasRole('admin', 'office'))
                    <div class="mf-panel">
                        <div class="mf-panel-header">
                            <div class="flex-1">
                                <div class="text-[13px] font-semibold" style="color: var(--ink);">Order queue</div>
                                <div class="text-[12px]" style="color: var(--muted); margin-top: 1px;">
                                    To confirm or allocate · oldest first
                                </div>
                            </div>
                            <a href="{{ route('orders.index') }}" class="mf-btn-secondary">View all orders</a>
                        </div>

                        @if ($unallocated->isEmpty())
                            <div class="px-4 py-10 text-center">
                                <div class="text-[13px] font-medium" style="color: var(--ink-2);">Queue is clear.</div>
                                <div class="mt-1 text-[12px]" style="color: var(--muted);">No confirmed orders need allocation right now.</div>
                            </div>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse text-[13px]">
                                    <thead>
                                        <tr style="color: var(--muted);" class="text-left uppercase text-[11px]">
                                            <th class="px-4 py-2.5 font-medium" style="letter-spacing: 0.5px;">Order</th>
                                            <th class="px-3 py-2.5 font-medium" style="letter-spacing: 0.5px;">Customer</th>
                                            @can('see-financials')
                                                <th class="px-3 py-2.5 font-medium" style="letter-spacing: 0.5px;">Value</th>
                                            @endcan
                                            <th class="px-3 py-2.5 font-medium" style="letter-spacing: 0.5px;">Delivery</th>
                                            <th class="px-3 py-2.5 font-medium" style="letter-spacing: 0.5px;">Status</th>
                                            <th class="px-4 py-2.5 font-medium"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($unallocated as $order)
                                            @php
                                                $totalOrdered = $order->orderItems->sum('quantity_ordered');
                                                $totalAllocated = $order->orderItems->sum('quantity_allocated');
                                                $isPartial = $totalAllocated > 0 && $totalAllocated < $totalOrdered;
                                                $itemDetail = $order->orderItems
                                                    ->take(3)
                                                    ->map(fn ($i) => $i->quantity_ordered.'× '.optional($i->productVariant)->name)
                                                    ->filter()
                                                    ->implode(' · ');
                                                if ($order->orderItems->count() > 3) {
                                                    $itemDetail .= ' · +'.($order->orderItems->count() - 3).' more';
                                                }
                                            @endphp
                                            <tr style="border-top: 1px solid var(--line-2);">
                                                <td class="px-4 py-3 font-mono text-[12.5px] whitespace-nowrap" style="color: var(--ink);">
                                                    {{ $order->order_number }}
                                                </td>
                                                <td class="px-3 py-3" title="{{ $itemDetail }}">
                                                    <div class="font-medium">{{ optional($order->customer)->name ?? '—' }}</div>
                                                </td>
                                                @can('see-financials')
                                                    <td class="px-3 py-3 font-mono font-medium">€{{ number_format((float) $order->total_amount, 2) }}</td>
                                                @endcan
                                                <td class="px-3 py-3 font-mono whitespace-nowrap" style="color: var(--muted);">
                                                    {{ $order->delivery_date?->format('d/m') ?? '—' }}
                                                    @if ($order->isDeliveryOverdue())
                                                        <span class="mf-tag mf-tag-danger">overdue</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-3">
                                                    @if ($order->status === 'pending')
                                                        <span class="mf-tag mf-tag-warn">
                                                            <span class="inline-block rounded-full" style="width: 5px; height: 5px; background: var(--warn-ink); opacity: 0.8;"></span>
                                                            Pending
                                                        </span>
                                                    @elseif ($isPartial)
                                                        <span class="mf-tag mf-tag-info">
                                                            <span class="inline-block rounded-full" style="width: 5px; height: 5px; background: var(--info); opacity: 0.8;"></span>
                                                            Partial
                                                        </span>
                                                    @else
                                                        <span class="mf-tag mf-tag-warn">
                                                            <span class="inline-block rounded-full" style="width: 5px; height: 5px; background: var(--warn-ink); opacity: 0.8;"></span>
                                                            Unallocated
                                                        </span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-3 text-right">
                                                    <a
                                                        href="{{ route('orders.show', $order) }}"
                                                        class="mf-btn-secondary text-[12px] px-2.5 py-1 whitespace-nowrap"
                                                    >{{ $order->status === 'pending' ? 'Confirm →' : 'Allocate →' }}</a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div
                                class="flex items-center justify-between px-4 py-2.5 text-[12px]"
                                style="border-top: 1px solid var(--line-2); color: var(--muted);"
                            >
                                <span>
                                    Showing {{ $unallocated->count() }} of {{ $unallocatedN }} to action
                                    @if ($readyN > 0)
                                        · <b style="color: var(--ink-2); font-weight: 500;">{{ $readyN }} ready</b>
                                    @endif
                                </span>
                                <a href="{{ route('orders.index') }}" class="font-medium" style="color: var(--accent-ink);">Open all orders →</a>
                            </div>
                        @endif
                    </div>
                @else
                    {{-- Factory users: queue is office-only. Show a production-focused intro card instead. --}}
                    <div class="mf-panel p-6">
                        <div class="text-[13px] font-semibold" style="color: var(--ink);">Today on the floor</div>
                        <p class="mt-2 text-[13px]" style="color: var(--ink-2); line-height: 1.6;">
                            Pick today's orders from the Picking queue. Use the sidebar to open Batches, Cheese Cutting,
                            or Stock — the Maturing panel on the right shows what's coming up next.
                        </p>
                        <div class="mt-4 flex gap-2 flex-wrap">
                            <a href="{{ route('picking.index') }}" class="mf-btn-primary">Picking queue →</a>
                            <a href="{{ route('batches.index') }}" class="mf-btn-secondary">Batches</a>
                            <a href="{{ route('cheese-cutting.index') }}" class="mf-btn-secondary">Cheese cutting</a>
                            <a href="{{ route('stock.index') }}" class="mf-btn-secondary">Stock</a>
                        </div>
                    </div>
                @endif

                {{-- Side rail --}}
                <div class="flex flex-col gap-4">
                    {{-- Maturing --}}
                    <div class="mf-panel">
                        <div class="mf-panel-header">
                            <div class="flex-1">
                                <div class="text-[13px] font-semibold">Maturing soon</div>
                                <div class="text-[12px]" style="color: var(--muted); margin-top: 1px;">Next to reach ready date</div>
                            </div>
                        </div>
                        @if (($maturingSoon ?? collect())->isEmpty())
                            <div class="px-4 py-6 text-center">
                                <div class="text-[12.5px]" style="color: var(--muted);">No active batches maturing.</div>
                            </div>
                        @else
                            @foreach ($maturingSoon as $idx => $batch)
                                @php
                                    $days = $batch->ready_date ? now()->startOfDay()->diffInDays($batch->ready_date, false) : null;
                                    if ($days === null || $days <= 0) {
                                        $when = 'Ready now'; $tone = 'accent';
                                    } elseif ($days <= 7) {
                                        $when = 'in '.$days.' day'.($days === 1 ? '' : 's'); $tone = 'warn';
                                    } else {
                                        $when = 'in '.$days.' days'; $tone = 'neutral';
                                    }
                                    $yield = $batch->batchItems->sum('quantity_produced') ?: ($batch->wheels_produced ?? 0);
                                    $productName = optional($batch->product)->name ?? 'Batch';
                                @endphp
                                <div class="px-4 py-3" @style(['border-top: 1px solid var(--line-2)' => $idx > 0])>
                                    <div class="flex items-center gap-2.5">
                                        <span class="text-[11.5px] font-mono" style="color: var(--muted);">{{ $batch->batch_code }}</span>
                                        <span class="flex-1 text-[13px] font-medium truncate">
                                            {{ $productName }}@if ($yield) · {{ $yield }} unit{{ $yield === 1 ? '' : 's' }}@endif
                                        </span>
                                        <span class="mf-tag mf-tag-{{ $tone }}">{{ $when }}</span>
                                    </div>
                                </div>
                            @endforeach
                            <div class="px-4 py-2.5" style="border-top: 1px solid var(--line-2);">
                                <a href="{{ route('batches.index') }}" class="text-[12px] font-medium" style="color: var(--accent-ink);">
                                    View all batches →
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-app-layout>
