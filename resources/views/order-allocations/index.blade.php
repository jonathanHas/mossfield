<x-app-layout>
    <x-slot name="header">Stock allocation</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Stock allocation</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Allocate available batch inventory to confirmed orders.</div>
            </div>
        </div>

        <div class="mf-flash mf-flash-warn mb-4" style="background: var(--info-soft); border-color: oklch(0.92 0.04 235); color: var(--info);">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" />
            </svg>
            <div>Only orders with status <b>confirmed</b> or <b>preparing</b> are shown here.</div>
        </div>

        <div class="mf-panel mb-4">
            <form method="GET" class="flex flex-wrap gap-3 items-end p-4">
                <div>
                    <label for="allocation_status" class="mf-label">Allocation status</label>
                    <select name="allocation_status" id="allocation_status" class="mf-select">
                        <option value="">All orders</option>
                        <option value="unallocated" {{ request('allocation_status') == 'unallocated' ? 'selected' : '' }}>Unallocated</option>
                        <option value="partially_allocated" {{ request('allocation_status') == 'partially_allocated' ? 'selected' : '' }}>Partially allocated</option>
                        <option value="fully_allocated" {{ request('allocation_status') == 'fully_allocated' ? 'selected' : '' }}>Fully allocated</option>
                    </select>
                </div>
                <button type="submit" class="mf-btn-secondary">Filter</button>
                @if(request('allocation_status'))
                    <a href="{{ route('order-allocations.index') }}" class="mf-btn-ghost">Clear</a>
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
                            <th class="mf-th">Status</th>
                            <th class="mf-th">Allocation</th>
                            <th class="mf-th">Items</th>
                            <th class="mf-th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($orders as $order)
                            @php
                                $totalItems = $order->orderItems->sum('quantity_ordered');
                                $totalAllocated = $order->orderItems->sum('quantity_allocated');
                                $allocationPercentage = $totalItems > 0 ? ($totalAllocated / $totalItems) * 100 : 0;

                                if ($allocationPercentage == 100) {
                                    $allocationStatus = 'Fully allocated';
                                    $allocationTone = 'accent';
                                } elseif ($allocationPercentage > 0) {
                                    $allocationStatus = 'Partial';
                                    $allocationTone = 'info';
                                } else {
                                    $allocationStatus = 'Unallocated';
                                    $allocationTone = 'warn';
                                }

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
                                    <a href="{{ route('order-allocations.show', $order) }}" class="mf-link">{{ $order->order_number }}</a>
                                </td>
                                <td class="mf-td">{{ $order->customer->name }}</td>
                                <td class="mf-td font-mono" style="color: var(--muted);">{{ $order->order_date->format('d/m/Y') }}</td>
                                <td class="mf-td"><span class="mf-tag mf-tag-{{ $statusTone }}">{{ ucfirst($order->status) }}</span></td>
                                <td class="mf-td">
                                    <span class="mf-tag mf-tag-{{ $allocationTone }}">{{ $allocationStatus }}</span>
                                    @if($allocationPercentage > 0 && $allocationPercentage < 100)
                                        <span class="text-[11.5px] font-mono ml-1" style="color: var(--muted);">{{ number_format($allocationPercentage, 0) }}%</span>
                                    @endif
                                </td>
                                <td class="mf-td font-mono" style="color: var(--muted);">{{ $totalAllocated }}/{{ $totalItems }}</td>
                                <td class="mf-td text-right">
                                    <a href="{{ route('order-allocations.show', $order) }}" class="mf-link">Manage</a>
                                    <span style="color: var(--faint);"> · </span>
                                    <a href="{{ route('orders.show', $order) }}" class="mf-link">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr style="border-top: 1px solid var(--line-2);">
                                <td colspan="7" class="mf-td text-center py-10" style="color: var(--muted);">No orders requiring allocation found.</td>
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
