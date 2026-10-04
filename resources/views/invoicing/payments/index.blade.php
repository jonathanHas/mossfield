<x-app-layout>
    <x-slot name="header">Payments</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Payments</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Money received, and what it settled.</div>
            </div>
            <a href="{{ route('invoicing.payments.create') }}" class="mf-btn-primary">Record a payment</a>
        </div>

        @include('invoicing.partials._nav')

        <div class="mf-panel mb-4">
            <form method="GET" class="flex flex-wrap gap-3 items-end p-4">
                <div>
                    <label for="customer_id" class="mf-label">Customer</label>
                    <select name="customer_id" id="customer_id" class="mf-select">
                        <option value="">All customers</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" @selected(request('customer_id') == $customer->id)>{{ $customer->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="mf-btn-secondary">Filter</button>
                <a href="{{ route('invoicing.payments.index') }}" class="mf-link text-[12.5px]">Clear</a>
            </form>
        </div>

        <div class="mf-panel">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="mf-th">Date</th>
                        <th class="mf-th">Customer</th>
                        <th class="mf-th">Method</th>
                        <th class="mf-th">Reference</th>
                        <th class="mf-th text-right">Amount</th>
                        <th class="mf-th text-right">On account</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($payments as $payment)
                        <tr class="mf-tr-divide">
                            <td class="mf-td">
                                <a href="{{ route('invoicing.payments.show', $payment) }}" class="mf-link">
                                    {{ $payment->received_at?->format('d/m/Y') }}
                                </a>
                            </td>
                            <td class="mf-td">{{ $payment->customer?->name }}</td>
                            <td class="mf-td">{{ $payment->method_label }}</td>
                            <td class="mf-td font-mono text-[12px]">{{ $payment->reference ?: '—' }}</td>
                            <td class="mf-td text-right font-mono">€{{ number_format((float) $payment->amount, 2) }}</td>
                            <td class="mf-td text-right font-mono">
                                @if($payment->unallocated_amount > 0)
                                    <span class="mf-tag mf-tag-warn">€{{ number_format($payment->unallocated_amount, 2) }}</span>
                                @else
                                    <span style="color: var(--muted);">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="mf-td text-center py-6" style="color: var(--muted);">No payments recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $payments->links() }}</div>
    </div>
</x-app-layout>
