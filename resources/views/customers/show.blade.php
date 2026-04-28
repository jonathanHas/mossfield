<x-app-layout>
    <x-slot name="header">{{ $customer->name }}</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <div class="text-[12px] font-mono" style="color: var(--muted);">Customer</div>
                <h1 class="mt-0.5 text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">{{ $customer->name }}</h1>
                <div class="mt-1 flex items-center gap-2">
                    @if($customer->is_active)
                        <span class="mf-tag mf-tag-accent">Active</span>
                    @else
                        <span class="mf-tag mf-tag-danger">Inactive</span>
                    @endif
                    @if($customer->hasOnlineAccount())
                        <span class="mf-tag mf-tag-info">Online · {{ $customer->mossorders_user_id }}</span>
                    @endif
                </div>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('customers.index') }}" class="mf-btn-ghost">← All customers</a>
                <a href="{{ route('customers.edit', $customer) }}" class="mf-btn-secondary">Edit</a>
            </div>
        </div>

        @if (session('success'))
            <div class="mf-flash mf-flash-success">{{ session('success') }}</div>
        @endif

        <div class="grid gap-4 grid-cols-1 lg:grid-cols-3 mb-4">
            <div class="mf-panel">
                <div class="mf-panel-header">
                    <div class="text-[13px] font-semibold">Basic information</div>
                </div>
                <dl class="px-4 py-3 text-[13px] grid grid-cols-[110px_1fr] gap-y-2">
                    <dt style="color: var(--muted);">Name</dt>
                    <dd>{{ $customer->name }}</dd>
                    <dt style="color: var(--muted);">Email</dt>
                    <dd>{{ $customer->email ?: '—' }}</dd>
                    @if($customer->phone)
                        <dt style="color: var(--muted);">Phone</dt>
                        <dd class="font-mono">{{ $customer->phone }}</dd>
                    @endif
                    <dt style="color: var(--muted);">Status</dt>
                    <dd>
                        @if($customer->is_active)
                            <span class="mf-tag mf-tag-accent">Active</span>
                        @else
                            <span class="mf-tag mf-tag-danger">Inactive</span>
                        @endif
                    </dd>
                </dl>
            </div>

            <div class="mf-panel">
                <div class="mf-panel-header">
                    <div class="text-[13px] font-semibold">Address</div>
                </div>
                <dl class="px-4 py-3 text-[13px] grid grid-cols-[110px_1fr] gap-y-2">
                    <dt style="color: var(--muted);">Street</dt>
                    <dd>{{ $customer->address ?: '—' }}</dd>
                    <dt style="color: var(--muted);">City</dt>
                    <dd>{{ $customer->city ?: '—' }}</dd>
                    <dt style="color: var(--muted);">Postal code</dt>
                    <dd class="font-mono">{{ $customer->postal_code ?: '—' }}</dd>
                    <dt style="color: var(--muted);">Country</dt>
                    <dd>{{ $customer->country ?: '—' }}</dd>
                </dl>
            </div>

            <div class="mf-panel">
                <div class="mf-panel-header">
                    <div class="text-[13px] font-semibold">Business terms</div>
                </div>
                <dl class="px-4 py-3 text-[13px] grid grid-cols-[110px_1fr] gap-y-2">
                    <dt style="color: var(--muted);">Credit limit</dt>
                    <dd class="font-mono">€{{ number_format($customer->credit_limit, 2) }}</dd>
                    <dt style="color: var(--muted);">Payment terms</dt>
                    <dd>
                        {{ match($customer->payment_terms) {
                            'immediate' => 'Immediate',
                            'net_7' => 'Net 7 days',
                            'net_14' => 'Net 14 days',
                            'net_30' => 'Net 30 days',
                            default => $customer->payment_terms
                        } }}
                    </dd>
                    <dt style="color: var(--muted);">Outstanding</dt>
                    <dd class="font-mono">€{{ number_format($customer->outstanding_balance, 2) }}</dd>
                    <dt style="color: var(--muted);">Online</dt>
                    <dd>
                        @if($customer->hasOnlineAccount())
                            <span class="mf-tag mf-tag-info">Linked · ID {{ $customer->mossorders_user_id }}</span>
                        @else
                            <span class="mf-tag mf-tag-neutral">Not linked</span>
                        @endif
                    </dd>
                </dl>
            </div>
        </div>

        @if($customer->notes)
            <div class="mf-flash mf-flash-warn mb-4">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" />
                </svg>
                <div><b>Notes:</b> <span class="whitespace-pre-wrap">{{ $customer->notes }}</span></div>
            </div>
        @endif

        <div class="mf-panel">
            <div class="mf-panel-header">
                <div class="flex-1">
                    <div class="text-[13px] font-semibold">Orders <span style="color: var(--muted); font-weight: 400;">({{ $customer->orders->count() }})</span></div>
                </div>
                <a href="{{ route('orders.create', ['customer_id' => $customer->id]) }}" class="mf-btn-secondary">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                    New order
                </a>
            </div>

            @if($customer->orders->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th class="mf-th">Order</th>
                                <th class="mf-th">Date</th>
                                <th class="mf-th">Total</th>
                                <th class="mf-th">Status</th>
                                <th class="mf-th">Payment</th>
                                <th class="mf-th">Source</th>
                                <th class="mf-th"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($customer->orders as $order)
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
                                        <a href="{{ route('orders.show', $order) }}" class="mf-link">{{ $order->order_number }}</a>
                                    </td>
                                    <td class="mf-td font-mono" style="color: var(--muted);">{{ $order->order_date->format('d M Y') }}</td>
                                    <td class="mf-td font-mono">€{{ number_format($order->total_amount, 2) }}</td>
                                    <td class="mf-td"><span class="mf-tag mf-tag-{{ $statusTone }}">{{ ucfirst($order->status) }}</span></td>
                                    <td class="mf-td"><span class="mf-tag mf-tag-{{ $payTone }}">{{ ucfirst(str_replace('_', ' ', $order->payment_status)) }}</span></td>
                                    <td class="mf-td">
                                        @if($order->mossorders_order_id)
                                            <span class="mf-tag mf-tag-info">Online</span>
                                        @else
                                            <span class="mf-tag mf-tag-neutral">Office</span>
                                        @endif
                                    </td>
                                    <td class="mf-td text-right">
                                        <a href="{{ route('orders.show', $order) }}" class="mf-link">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-4 py-10 text-center">
                    <div class="text-[13px]" style="color: var(--muted);">
                        No orders yet. <a href="{{ route('orders.create', ['customer_id' => $customer->id]) }}" class="mf-link">Create the first order</a>.
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
