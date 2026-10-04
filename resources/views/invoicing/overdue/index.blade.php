<x-app-layout>
    <x-slot name="header">Overdue</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Overdue invoices</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                    €{{ number_format($total, 2) }} outstanding past its due date, oldest first.
                </div>
            </div>
            @if($invoices->isNotEmpty())
                <form method="POST" action="{{ route('invoicing.overdue.remind-all') }}"
                      data-confirm="Send a reminder for every overdue invoice not already reminded today?" data-confirm-label="Send">
                    @csrf
                    <button type="submit" class="mf-btn-primary">Remind everyone</button>
                </form>
            @endif
        </div>

        @include('invoicing.partials._nav')

        @if($invoices->isEmpty())
            <div class="mf-panel px-4 py-6 text-[13px]" style="color: var(--muted);">
                Nothing overdue. Every issued invoice is either paid or still within terms.
            </div>
        @else
            <div class="mf-panel">
                <table class="w-full">
                    <thead>
                        <tr>
                            <th class="mf-th">Invoice</th>
                            <th class="mf-th">Customer</th>
                            <th class="mf-th">Due</th>
                            <th class="mf-th text-right">Days late</th>
                            <th class="mf-th text-right">Outstanding</th>
                            <th class="mf-th">Last reminded</th>
                            <th class="mf-th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($invoices as $invoice)
                            <tr class="mf-tr-divide">
                                <td class="mf-td">
                                    <a href="{{ route('invoicing.invoices.show', $invoice) }}" class="mf-link font-mono">{{ $invoice->number }}</a>
                                </td>
                                <td class="mf-td">{{ $invoice->customer?->name }}</td>
                                <td class="mf-td">{{ $invoice->due_date?->format('d/m/Y') }}</td>
                                <td class="mf-td text-right">
                                    <span class="mf-tag mf-tag-danger">{{ $invoice->days_overdue }}</span>
                                </td>
                                <td class="mf-td text-right font-mono">€{{ number_format((float) $invoice->balance, 2) }}</td>
                                <td class="mf-td text-[12.5px]" style="color: var(--muted);">
                                    {{ $invoice->last_reminded_at?->format('d/m/Y') ?? '—' }}
                                </td>
                                <td class="mf-td text-right">
                                    @if($invoice->canBeReminded())
                                        <form method="POST" action="{{ route('invoicing.invoices.remind', $invoice) }}">
                                            @csrf
                                            <button type="submit" class="mf-btn-secondary">Remind</button>
                                        </form>
                                    @else
                                        <span class="text-[12px]" style="color: var(--muted);">No email</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
