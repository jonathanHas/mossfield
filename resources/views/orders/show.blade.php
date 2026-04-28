<x-app-layout>
    <x-slot name="header">Order {{ $order->order_number }}</x-slot>

    @php
        $statusTone = match($order->status) {
            'pending' => 'warn',
            'confirmed', 'preparing' => 'info',
            'ready', 'dispatched', 'delivered' => 'accent',
            'cancelled' => 'danger',
            default => 'neutral',
        };
        $payTone = match($order->payment_status) {
            'paid' => 'accent',
            'partial' => 'info',
            'overdue' => 'danger',
            default => 'warn',
        };
        $listFilters = $listFilters ?? [];
        $orderList = $orderList ?? collect();
        $listTotal = $listTotal ?? $orderList->count();
        $listLimit = $listLimit ?? 50;
        $hasFilters = ! empty($listFilters);
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-[320px_1fr]">
        <aside class="hidden lg:flex lg:flex-col" style="border-right: 1px solid var(--line-2); max-height: calc(100vh - var(--header-height, 64px)); overflow-y: auto;">
            <div class="px-4 py-3 sticky top-0 z-10" style="background: var(--bg); border-bottom: 1px solid var(--line-2);">
                <div class="flex items-center justify-between gap-2">
                    <div class="text-[13px] font-semibold">Orders</div>
                    <a href="{{ route('orders.index', $listFilters) }}" class="mf-link text-[12px]">All orders →</a>
                </div>
                @if($hasFilters)
                    <div class="mt-1 text-[11.5px]" style="color: var(--muted);">
                        Filtered ·
                        @foreach($listFilters as $k => $v)
                            <span class="font-mono">{{ $k }}={{ $v }}</span>{{ ! $loop->last ? ' · ' : '' }}
                        @endforeach
                    </div>
                @endif
            </div>

            <ul class="flex-1">
                @foreach($orderList as $sibling)
                    @php
                        $isActive = $sibling->id === $order->id;
                        $sibStatusTone = match($sibling->status) {
                            'pending' => 'warn',
                            'confirmed', 'preparing' => 'info',
                            'ready', 'dispatched', 'delivered' => 'accent',
                            'cancelled' => 'danger',
                            default => 'neutral',
                        };
                        $itemSummary = $sibling->orderItems->take(3)->map(function ($i) {
                            return $i->quantity_ordered.'× '.$i->productVariant->product->name;
                        })->implode(' · ');
                        $extra = $sibling->orderItems->count() - 3;
                    @endphp
                    <li>
                        <a href="{{ route('orders.show', array_merge($listFilters, ['order' => $sibling->id])) }}"
                           class="block px-4 py-3 transition-colors"
                           style="border-bottom: 1px solid var(--line-2); {{ $isActive ? 'background: var(--accent-soft, #f5f3ee); border-left: 3px solid var(--accent, #4a7c4d); padding-left: 13px;' : '' }}">
                            <div class="flex items-baseline justify-between gap-2">
                                <div class="font-mono text-[12px]" style="color: var(--muted);">{{ $sibling->order_number }}</div>
                                <div class="text-[11px]" style="color: var(--muted);">{{ $sibling->order_date->diffForHumans(null, true, true) }}</div>
                            </div>
                            <div class="mt-0.5 text-[13px] font-medium truncate">{{ $sibling->customer->name }}</div>
                            @if($itemSummary)
                                <div class="mt-0.5 text-[11.5px] truncate" style="color: var(--muted);">
                                    {{ $itemSummary }}{{ $extra > 0 ? ' · +'.$extra : '' }}
                                </div>
                            @endif
                            <div class="mt-1.5 flex items-center justify-between gap-2">
                                <span class="mf-tag mf-tag-{{ $sibStatusTone }}">{{ ucfirst($sibling->status) }}</span>
                                @can('see-financials')
                                    <span class="font-mono text-[12px] font-medium">€{{ number_format($sibling->total_amount, 2) }}</span>
                                @endcan
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if($listTotal > $listLimit)
                <div class="px-4 py-3 text-[12px]" style="color: var(--muted); border-top: 1px solid var(--line-2);">
                    Showing {{ $listLimit }} of {{ $listTotal }} ·
                    <a href="{{ route('orders.index', $listFilters) }}" class="mf-link">view all</a>
                </div>
            @endif
        </aside>

        <main class="px-6 py-5">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
                <div>
                    <div class="text-[12px] font-mono" style="color: var(--muted);">{{ $order->order_date->format('l, j F Y') }}</div>
                    <h1 class="mt-0.5 text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">
                        Order <span class="font-mono text-[20px]">{{ $order->order_number }}</span>
                    </h1>
                    <div class="mt-1 flex items-center gap-2">
                        <span class="mf-tag mf-tag-{{ $statusTone }}">{{ ucfirst($order->status) }}</span>
                        <span class="mf-tag mf-tag-{{ $payTone }}">{{ ucfirst($order->payment_status) }}</span>
                    </div>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('orders.index', $listFilters) }}" class="mf-btn-ghost lg:hidden">← All orders</a>
                    @can('update', $order)
                        <a href="{{ route('orders.edit', $order) }}" class="mf-btn-secondary">Edit</a>
                    @endcan
                </div>
            </div>

            <div class="grid gap-4 grid-cols-1 xl:grid-cols-2 mb-4">
                <div class="mf-panel">
                    <div class="mf-panel-header">
                        <div class="text-[13px] font-semibold">Order information</div>
                    </div>
                    <dl class="px-4 py-3 text-[13px] grid grid-cols-[140px_1fr] gap-y-2">
                        <dt style="color: var(--muted);">Order number</dt>
                        <dd class="font-mono">{{ $order->order_number }}</dd>
                        <dt style="color: var(--muted);">Order date</dt>
                        <dd class="font-mono">{{ $order->order_date->format('d/m/Y') }}</dd>
                        <dt style="color: var(--muted);">Delivery date</dt>
                        <dd class="font-mono">{{ $order->delivery_date ? $order->delivery_date->format('d/m/Y') : '—' }}</dd>
                        <dt style="color: var(--muted);">Status</dt>
                        <dd><span class="mf-tag mf-tag-{{ $statusTone }}">{{ ucfirst($order->status) }}</span></dd>
                        <dt style="color: var(--muted);">Payment</dt>
                        <dd><span class="mf-tag mf-tag-{{ $payTone }}">{{ ucfirst($order->payment_status) }}</span></dd>
                    </dl>
                </div>

                <div class="mf-panel">
                    <div class="mf-panel-header">
                        <div class="text-[13px] font-semibold">Customer</div>
                    </div>
                    <dl class="px-4 py-3 text-[13px] grid grid-cols-[140px_1fr] gap-y-2">
                        <dt style="color: var(--muted);">Name</dt>
                        <dd>{{ $order->customer->name }}</dd>
                        <dt style="color: var(--muted);">Email</dt>
                        <dd>{{ $order->customer->email ?: '—' }}</dd>
                        <dt style="color: var(--muted);">Phone</dt>
                        <dd>{{ $order->customer->phone ?: '—' }}</dd>
                        <dt style="color: var(--muted);">Address</dt>
                        <dd>{{ $order->customer->address ?: '—' }}</dd>
                        @if($order->delivery_address)
                            <dt style="color: var(--muted);">Delivery to</dt>
                            <dd>{{ $order->delivery_address }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            @if($order->notes)
                <div class="mf-flash mf-flash-warn mb-4">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" />
                    </svg>
                    <div><b>Notes:</b> {{ $order->notes }}</div>
                </div>
            @endif

            <div class="mf-panel mb-4">
                <div class="mf-panel-header">
                    <div class="text-[13px] font-semibold">Order items</div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th class="mf-th">Product</th>
                                @can('see-financials')
                                    <th class="mf-th">Unit price</th>
                                @endcan
                                <th class="mf-th">Ordered</th>
                                <th class="mf-th">Allocated</th>
                                <th class="mf-th">Fulfilled</th>
                                @can('see-financials')
                                    <th class="mf-th text-right">Line total</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($order->orderItems as $item)
                                <tr style="border-top: 1px solid var(--line-2);">
                                    <td class="mf-td">
                                        <div class="font-medium">{{ $item->productVariant->product->name }}</div>
                                        <div class="text-[11.5px] mt-0.5" style="color: var(--muted);">{{ $item->productVariant->name }}</div>
                                    </td>
                                    @can('see-financials')
                                        <td class="mf-td font-mono">€{{ number_format($item->unit_price, 2) }}{{ $item->isPricedByWeight() ? '/kg' : '' }}</td>
                                    @endcan
                                    <td class="mf-td font-mono">{{ $item->quantity_ordered }}</td>
                                    <td class="mf-td font-mono">{{ $item->quantity_allocated ?? 0 }}</td>
                                    <td class="mf-td font-mono">{{ $item->quantity_fulfilled ?? 0 }}</td>
                                    @can('see-financials')
                                        <td class="mf-td font-mono font-medium text-right">€{{ number_format($item->line_total, 2) }}</td>
                                    @endcan
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @can('see-financials')
                    <div class="px-4 py-3 flex justify-end" style="border-top: 1px solid var(--line-2);">
                        <dl class="text-[13px] grid grid-cols-[120px_120px] gap-y-1.5">
                            <dt style="color: var(--muted);">Subtotal</dt>
                            <dd class="text-right font-mono">€{{ number_format($order->subtotal, 2) }}</dd>
                            <dt style="color: var(--muted);">Tax</dt>
                            <dd class="text-right font-mono">€{{ number_format($order->tax_amount, 2) }}</dd>
                            <dt class="font-semibold pt-1" style="border-top: 1px solid var(--line-2);">Total</dt>
                            <dd class="text-right font-mono font-semibold pt-1 text-[15px]" style="border-top: 1px solid var(--line-2);">€{{ number_format($order->total_amount, 2) }}</dd>
                        </dl>
                    </div>
                @endcan
            </div>

            <div class="flex justify-between items-center">
                <div class="text-[12px]" style="color: var(--muted);">
                    Created {{ $order->created_at->format('d/m/Y H:i') }}
                    @if($order->updated_at != $order->created_at)
                        · Last updated {{ $order->updated_at->format('d/m/Y H:i') }}
                    @endif
                </div>
                @can('update', $order)
                    <div class="flex gap-2">
                        @if($order->status === 'pending')
                            <form method="POST" action="{{ route('orders.update', $order) }}" class="inline">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="confirmed">
                                <input type="hidden" name="payment_status" value="{{ $order->payment_status }}">
                                <input type="hidden" name="delivery_date" value="{{ $order->delivery_date?->format('Y-m-d') }}">
                                <input type="hidden" name="delivery_address" value="{{ $order->delivery_address }}">
                                <input type="hidden" name="notes" value="{{ $order->notes }}">
                                <button type="submit"
                                    onclick="return confirm('Confirm this order and make it available for stock allocation?')"
                                    class="mf-btn-primary">
                                    Confirm order
                                </button>
                            </form>
                        @endif
                        @if($order->status === 'confirmed')
                            <a href="{{ route('order-allocations.show', $order) }}" class="mf-btn-primary">
                                Manage allocation →
                            </a>
                        @endif
                        @if($order->canBeCancelled())
                            <form method="POST" action="{{ route('orders.update', $order) }}" class="inline">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="cancelled">
                                <input type="hidden" name="payment_status" value="{{ $order->payment_status }}">
                                <input type="hidden" name="delivery_date" value="{{ $order->delivery_date?->format('Y-m-d') }}">
                                <input type="hidden" name="delivery_address" value="{{ $order->delivery_address }}">
                                <input type="hidden" name="notes" value="{{ $order->notes }}">
                                <button type="submit"
                                    onclick="return confirm('Are you sure you want to cancel this order?')"
                                    class="mf-btn-danger">
                                    Cancel order
                                </button>
                            </form>
                        @endif
                    </div>
                @endcan
            </div>
        </main>
    </div>
</x-app-layout>
