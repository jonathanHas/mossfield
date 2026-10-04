<x-app-layout>
    <x-slot name="header">Payment</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">
                    €{{ number_format((float) $payment->amount, 2) }}
                </h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                    <a href="{{ route('customers.show', $payment->customer) }}" class="mf-link">{{ $payment->customer?->name }}</a>
                    · {{ $payment->method_label }}
                    · received {{ $payment->received_at?->format('j M Y') }}
                    @if($payment->recorder) · recorded by {{ $payment->recorder->name }} @endif
                </div>
            </div>
            @can('delete', $payment)
                <form method="POST" action="{{ route('invoicing.payments.destroy', $payment) }}"
                      onsubmit="return confirm('Remove this payment? The invoices it settled will go back to outstanding.')">
                    @csrf @method('DELETE')
                    <button type="submit" class="mf-btn-danger">Remove payment</button>
                </form>
            @endcan
        </div>


        @if($payment->unallocated_amount > 0)
            <div class="mf-flash mf-flash-warn">
                <div>
                    <b>€{{ number_format($payment->unallocated_amount, 2) }} is unapplied</b> and sitting on account as credit.
                    It can be applied to future invoices from the customer's page.
                </div>
            </div>
        @endif

        <div class="mf-panel">
            <div class="mf-panel-header"><div class="text-[13px] font-semibold">Applied to</div></div>
            @if($payment->allocations->isEmpty())
                <div class="px-4 py-5 text-[13px]" style="color: var(--muted);">
                    Not applied to any invoice — the whole amount is credit on account.
                </div>
            @else
                <table class="w-full">
                    <thead>
                        <tr>
                            <th class="mf-th">Invoice</th>
                            <th class="mf-th">Issued</th>
                            <th class="mf-th text-right">Invoice total</th>
                            <th class="mf-th text-right">Applied</th>
                            <th class="mf-th text-right">Still due</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($payment->allocations as $allocation)
                            <tr class="mf-tr-divide">
                                <td class="mf-td">
                                    <a href="{{ route('invoicing.invoices.show', $allocation->invoice) }}" class="mf-link font-mono">
                                        {{ $allocation->invoice?->number }}
                                    </a>
                                </td>
                                <td class="mf-td">{{ $allocation->invoice?->issued_at?->format('d/m/Y') }}</td>
                                <td class="mf-td text-right font-mono">€{{ number_format((float) $allocation->invoice?->total, 2) }}</td>
                                <td class="mf-td text-right font-mono">€{{ number_format((float) $allocation->amount, 2) }}</td>
                                <td class="mf-td text-right font-mono">€{{ number_format((float) $allocation->invoice?->balance, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if(filled($payment->notes))
            <div class="mf-panel mt-4">
                <div class="mf-panel-header"><div class="text-[13px] font-semibold">Notes</div></div>
                <div class="px-4 py-3 text-[13px] whitespace-pre-wrap">{{ $payment->notes }}</div>
            </div>
        @endif
    </div>
</x-app-layout>
