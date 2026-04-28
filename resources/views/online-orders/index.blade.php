<x-app-layout>
    <x-slot name="header">Online orders</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Online orders</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Mossorders integration · imported orders, sync status, and customer mapping.</div>
            </div>
            <a href="{{ route('online-orders.preview') }}" class="mf-btn-primary">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 8.82a15 15 0 0 1 20 0" /><path d="M5 12.859a10 10 0 0 1 14 0" /><path d="M8.5 16.429a5 5 0 0 1 7 0" /><path d="M12 20h.01" />
                </svg>
                Preview & import
            </a>
        </div>

        @if (session('success'))
            <div class="mf-flash mf-flash-success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mf-flash mf-flash-error">{{ session('error') }}</div>
        @endif

        <div class="mf-panel mb-4">
            <div class="mf-panel-header">
                <div class="text-[13px] font-semibold">Mossorders integration</div>
            </div>
            <div class="px-4 py-3 flex flex-wrap items-center gap-3">
                @if($apiConfigured)
                    <span class="mf-tag mf-tag-accent">API configured</span>
                @else
                    <span class="mf-tag mf-tag-danger">Not configured</span>
                    <div class="text-[12px]" style="color: var(--muted);">
                        Set <code class="font-mono px-1 rounded" style="background: var(--bg);">MOSSORDERS_BASE_URL</code> and
                        <code class="font-mono px-1 rounded" style="background: var(--bg);">MOSSORDERS_API_TOKEN</code> in your <code class="font-mono">.env</code>.
                    </div>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <x-dash-kpi label="Imported orders" :value="$totalImported" sub="from Mossorders" />
            <x-dash-kpi label="Pending" :value="$pendingImported" :sub="$pendingImported > 0 ? 'need processing' : 'clear'" :tone="$pendingImported > 0 ? 'warn' : null" />
            <x-dash-kpi label="Linked customers" :value="$linkedCustomers" :sub="'of '.$totalCustomers" />
            <x-dash-kpi
                label="Last import"
                :value="$lastImportedOrder ? $lastImportedOrder->created_at->diffForHumans(['short' => true, 'parts' => 1]) : 'Never'"
                :sub="$lastImportedOrder?->order_number"
            />
        </div>

        <div class="mf-panel mb-4">
            <div class="mf-panel-header">
                <div class="text-[13px] font-semibold">Quick actions</div>
            </div>
            <div class="px-4 py-3 flex flex-wrap gap-2">
                <a href="{{ route('online-orders.preview') }}" class="mf-btn-secondary">Preview orders</a>
                <a href="{{ route('customers.index', ['has_online_account' => '0']) }}" class="mf-btn-secondary">Link customers</a>
                <a href="{{ route('orders.index') }}" class="mf-btn-secondary">All orders</a>
            </div>
        </div>

        <div class="mf-panel">
            <div class="mf-panel-header">
                <div class="text-[13px] font-semibold">Recently imported orders</div>
            </div>
            @if($recentImports->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th class="mf-th">Order</th>
                                <th class="mf-th">Customer</th>
                                <th class="mf-th text-right">Total</th>
                                <th class="mf-th">Status</th>
                                <th class="mf-th">Imported</th>
                                <th class="mf-th"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentImports as $order)
                                @php
                                    $statusTone = match($order->status) {
                                        'pending' => 'warn',
                                        'confirmed', 'preparing' => 'info',
                                        'ready', 'dispatched', 'delivered' => 'accent',
                                        'cancelled' => 'danger',
                                        default => 'neutral',
                                    };
                                @endphp
                                <tr style="border-top: 1px solid var(--line-2);">
                                    <td class="mf-td font-mono text-[12.5px]">
                                        <a href="{{ route('orders.show', $order) }}" class="mf-link">{{ $order->order_number }}</a>
                                        <div class="text-[11px] mt-0.5" style="color: var(--info);">Mossorders #{{ $order->mossorders_order_id }}</div>
                                    </td>
                                    <td class="mf-td">{{ $order->customer->name ?? 'Unknown' }}</td>
                                    <td class="mf-td font-mono text-right">€{{ number_format($order->total_amount, 2) }}</td>
                                    <td class="mf-td"><span class="mf-tag mf-tag-{{ $statusTone }}">{{ ucfirst($order->status) }}</span></td>
                                    <td class="mf-td font-mono" style="color: var(--muted);">{{ $order->created_at->diffForHumans() }}</td>
                                    <td class="mf-td text-right">
                                        <a href="{{ route('orders.show', $order) }}" class="mf-link">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-4 py-10 text-center" style="color: var(--muted);">
                    <div class="text-[13px]">No orders have been imported yet.</div>
                    <a href="{{ route('online-orders.preview') }}" class="mf-link mt-2 inline-block">Preview available orders →</a>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
