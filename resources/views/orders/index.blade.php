<x-app-layout>
    <x-slot name="header">Orders</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Order Management</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">All orders, sorted by recency.</div>
            </div>
            @can('create', App\Models\Order::class)
                <a href="{{ route('orders.create') }}" class="mf-btn-primary">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 5v14M5 12h14" />
                    </svg>
                    New order
                </a>
            @endcan
        </div>

        @if (session('success'))
            <div class="mf-flash mf-flash-success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mf-flash mf-flash-error">{{ session('error') }}</div>
        @endif

        <div class="mf-panel mb-4">
            <form method="GET" class="flex flex-wrap gap-3 items-end p-4">
                <div>
                    <label for="status" class="mf-label">Status</label>
                    <select name="status" id="status" class="mf-select">
                        <option value="">All statuses</option>
                        <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="confirmed" {{ request('status') == 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                        <option value="preparing" {{ request('status') == 'preparing' ? 'selected' : '' }}>Preparing</option>
                        <option value="ready" {{ request('status') == 'ready' ? 'selected' : '' }}>Ready</option>
                        <option value="dispatched" {{ request('status') == 'dispatched' ? 'selected' : '' }}>Dispatched</option>
                        <option value="delivered" {{ request('status') == 'delivered' ? 'selected' : '' }}>Delivered</option>
                        <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                    </select>
                </div>
                <div>
                    <label for="payment_status" class="mf-label">Payment</label>
                    <select name="payment_status" id="payment_status" class="mf-select">
                        <option value="">All</option>
                        <option value="pending" {{ request('payment_status') == 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="paid" {{ request('payment_status') == 'paid' ? 'selected' : '' }}>Paid</option>
                        <option value="partial" {{ request('payment_status') == 'partial' ? 'selected' : '' }}>Partial</option>
                        <option value="overdue" {{ request('payment_status') == 'overdue' ? 'selected' : '' }}>Overdue</option>
                    </select>
                </div>
                <div>
                    <label for="customer_id" class="mf-label">Customer</label>
                    <select name="customer_id" id="customer_id" class="mf-select">
                        <option value="">All customers</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" {{ request('customer_id') == $customer->id ? 'selected' : '' }}>
                                {{ $customer->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="mf-btn-secondary">Filter</button>
                @if(request()->hasAny(['status', 'payment_status', 'customer_id']))
                    <a href="{{ route('orders.index') }}" class="mf-btn-ghost">Clear</a>
                @endif
            </form>
        </div>

        <div class="mf-panel">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th class="mf-th">Order</th>
                            <th class="mf-th">Customer</th>
                            <th class="mf-th">Order date</th>
                            <th class="mf-th">Delivery</th>
                            <th class="mf-th">Status</th>
                            <th class="mf-th">Payment</th>
                            @can('see-financials')
                                <th class="mf-th">Total</th>
                            @endcan
                            <th class="mf-th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $rowFilters = array_filter(request()->only(['status', 'payment_status', 'customer_id']), fn ($v) => $v !== null && $v !== '');
                        @endphp
                        @forelse($orders as $order)
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
                            @endphp
                            <tr style="border-top: 1px solid var(--line-2);">
                                <td class="mf-td font-mono text-[12.5px]">
                                    <a href="{{ route('orders.show', array_merge($rowFilters, ['order' => $order->id])) }}" class="mf-link">{{ $order->order_number }}</a>
                                </td>
                                <td class="mf-td">{{ $order->customer->name }}</td>
                                <td class="mf-td font-mono" style="color: var(--muted);">{{ $order->order_date->format('d/m/Y') }}</td>
                                <td class="mf-td font-mono" style="color: var(--muted);">{{ $order->delivery_date ? $order->delivery_date->format('d/m/Y') : '—' }}</td>
                                <td class="mf-td"><span class="mf-tag mf-tag-{{ $statusTone }}">{{ ucfirst($order->status) }}</span></td>
                                <td class="mf-td"><span class="mf-tag mf-tag-{{ $payTone }}">{{ ucfirst($order->payment_status) }}</span></td>
                                @can('see-financials')
                                    <td class="mf-td font-mono font-medium">€{{ number_format($order->total_amount, 2) }}</td>
                                @endcan
                                <td class="mf-td text-right">
                                    <a href="{{ route('orders.show', array_merge($rowFilters, ['order' => $order->id])) }}" class="mf-link">View</a>
                                    @can('update', $order)
                                        <span style="color: var(--faint);"> · </span>
                                        <a href="{{ route('orders.edit', $order) }}" class="mf-link">Edit</a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr style="border-top: 1px solid var(--line-2);">
                                <td colspan="8" class="mf-td text-center py-10" style="color: var(--muted);">No orders found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($orders->hasPages())
                <div class="px-4 py-3" style="border-top: 1px solid var(--line-2);">
                    {{ $orders->withQueryString()->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
