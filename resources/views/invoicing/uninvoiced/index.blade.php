<x-app-layout>
    <x-slot name="header">To invoice</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Awaiting invoice</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                    Dispatch issues invoices automatically, so this is the exceptions list: deliveries where the
                    auto-issue failed, customers on weekly/monthly billing waiting for their period run, and
                    anything picked but not yet dispatched.
                </div>
            </div>
            @if($perOrder->isNotEmpty())
                <form method="POST" action="{{ route('invoicing.uninvoiced.issue-all') }}"
                      data-confirm-title="Issue these invoices?" data-confirm="Issue {{ $perOrder->count() }} invoices?" data-confirm-label="Issue">
                    @csrf
                    <button type="submit" class="mf-btn-primary whitespace-nowrap">Issue all {{ $perOrder->count() }}</button>
                </form>
            @endif
        </div>

        @include('invoicing.partials._nav')

        <div class="mf-panel mb-4">
            <div class="mf-panel-header">
                <div class="text-[13px] font-semibold">
                    Per-order billing
                    <span style="color: var(--muted); font-weight: 400;">({{ $perOrder->count() }})</span>
                </div>
            </div>
            @if($perOrder->isEmpty())
                <div class="px-4 py-5 text-[13px]" style="color: var(--muted);">
                    Nothing waiting. Every delivered order has been invoiced.
                </div>
            @else
                <table class="w-full">
                    <thead>
                        <tr>
                            <th class="mf-th">Order</th>
                            <th class="mf-th">Customer</th>
                            <th class="mf-th">Delivery</th>
                            <th class="mf-th">Status</th>
                            <th class="mf-th text-right">Total</th>
                            <th class="mf-th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($perOrder as $order)
                            <tr class="mf-tr-divide">
                                <td class="mf-td">
                                    <a href="{{ route('orders.show', $order) }}" class="mf-link font-mono">{{ $order->order_number }}</a>
                                    @if(filled($order->customer_reference))
                                        <div class="text-[11.5px]" style="color: var(--muted);">ref {{ $order->customer_reference }}</div>
                                    @endif
                                </td>
                                <td class="mf-td">{{ $order->customer?->name }}</td>
                                <td class="mf-td">{{ $order->billing_date?->format('d/m/Y') }}</td>
                                <td class="mf-td"><span class="mf-tag mf-tag-accent">{{ $order->statusLabel() }}</span></td>
                                <td class="mf-td text-right font-mono">€{{ number_format((float) $order->total_amount, 2) }}</td>
                                <td class="mf-td text-right">
                                    <form method="POST" action="{{ route('invoicing.invoices.issue-order', $order) }}">
                                        @csrf
                                        <button type="submit" class="mf-btn-secondary">Issue invoice</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="mf-panel mb-4">
            <div class="mf-panel-header">
                <div class="flex-1">
                    <div class="text-[13px] font-semibold">
                        Awaiting a consolidated run
                        <span style="color: var(--muted); font-weight: 400;">({{ $consolidated->flatten()->count() }})</span>
                    </div>
                </div>
                <a href="{{ route('invoicing.consolidated.create') }}" class="mf-btn-secondary">Run consolidated invoices</a>
            </div>
            @if($consolidated->isEmpty())
                <div class="px-4 py-5 text-[13px]" style="color: var(--muted);">
                    No customers are on weekly or monthly billing with deliveries outstanding.
                </div>
            @else
                <table class="w-full">
                    <thead>
                        <tr>
                            <th class="mf-th">Customer</th>
                            <th class="mf-th">Billing</th>
                            <th class="mf-th">Orders</th>
                            <th class="mf-th">Earliest</th>
                            <th class="mf-th text-right">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($consolidated as $orders)
                            @php $customer = $orders->first()->customer; @endphp
                            <tr class="mf-tr-divide">
                                <td class="mf-td">
                                    <a href="{{ route('customers.show', $customer) }}" class="mf-link">{{ $customer?->name }}</a>
                                </td>
                                <td class="mf-td">
                                    <span class="mf-tag mf-tag-info">{{ $customer?->invoicing_mode?->label() }}</span>
                                </td>
                                <td class="mf-td">{{ $orders->count() }}</td>
                                <td class="mf-td">{{ $orders->min(fn ($o) => $o->billing_date)?->format('d/m/Y') }}</td>
                                <td class="mf-td text-right font-mono">€{{ number_format($orders->sum(fn ($o) => (float) $o->total_amount), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Ready orders can still change at the van, so they aren't billable
             until dispatched (F-38a) — shown here so nothing is forgotten. --}}
        <div class="mf-panel" data-awaiting-dispatch>
            <div class="mf-panel-header">
                <div class="text-[13px] font-semibold">
                    Picked, awaiting dispatch
                    <span style="color: var(--muted); font-weight: 400;">({{ $awaitingDispatch->count() }})</span>
                </div>
            </div>
            @if($awaitingDispatch->isEmpty())
                <div class="px-4 py-5 text-[13px]" style="color: var(--muted);">
                    Nothing picked and waiting for the van.
                </div>
            @else
                <div class="px-4 pt-3 text-[12.5px]" style="color: var(--muted);">
                    Invoiced automatically when dispatched.
                </div>
                <table class="w-full">
                    <thead>
                        <tr>
                            <th class="mf-th">Order</th>
                            <th class="mf-th">Customer</th>
                            <th class="mf-th">Delivery</th>
                            <th class="mf-th">Loaded</th>
                            <th class="mf-th text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($awaitingDispatch as $order)
                            <tr class="mf-tr-divide">
                                <td class="mf-td">
                                    <a href="{{ route('orders.show', $order) }}" class="mf-link font-mono">{{ $order->order_number }}</a>
                                </td>
                                <td class="mf-td">{{ $order->customer?->name }}</td>
                                <td class="mf-td">{{ $order->delivery_date?->format('d/m/Y') }}</td>
                                <td class="mf-td">{{ $order->loaded_at ? 'Yes' : 'Not yet' }}</td>
                                <td class="mf-td text-right font-mono">€{{ number_format((float) $order->total_amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-app-layout>
