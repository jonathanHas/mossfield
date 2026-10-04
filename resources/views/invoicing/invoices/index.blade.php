<x-app-layout>
    <x-slot name="header">Invoices</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Invoices</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Issued invoices, what's been sent, and what's still owed.</div>
            </div>
        </div>

        @include('invoicing.partials._nav')

        {{-- The three numbers that mean "do something now". Each is a link
             straight into the queue it counts. --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
            <a href="{{ route('invoicing.invoices.index', ['emailed' => 0]) }}">
                <x-dash-kpi label="Not yet emailed" :value="$stats['not_emailed']"
                    :sub="$stats['not_emailed'] > 0 ? 'to send' : null"
                    :tone="$stats['not_emailed'] > 0 ? 'warn' : null" />
            </a>
            <a href="{{ route('invoicing.uninvoiced.index') }}">
                <x-dash-kpi label="Awaiting invoice" :value="$stats['uninvoiced']"
                    :sub="$stats['uninvoiced'] > 0 ? 'to bill' : null"
                    :tone="$stats['uninvoiced'] > 0 ? 'warn' : null" />
            </a>
            <a href="{{ route('invoicing.overdue.index') }}">
                <x-dash-kpi label="Overdue" :value="'€'.number_format($stats['overdue_total'], 2)"
                    :sub="$stats['overdue_count'] > 0 ? $stats['overdue_count'].' invoices' : null"
                    :tone="$stats['overdue_count'] > 0 ? 'danger' : null" />
            </a>
        </div>

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
                <div>
                    <label for="payment_state" class="mf-label">Payment</label>
                    <select name="payment_state" id="payment_state" class="mf-select">
                        <option value="">Any</option>
                        <option value="unpaid" @selected(request('payment_state') === 'unpaid')>Unpaid</option>
                        <option value="partial" @selected(request('payment_state') === 'partial')>Part paid</option>
                        <option value="paid" @selected(request('payment_state') === 'paid')>Paid</option>
                        <option value="overdue" @selected(request('payment_state') === 'overdue')>Overdue</option>
                    </select>
                </div>
                <div>
                    <label for="emailed" class="mf-label">Emailed</label>
                    <select name="emailed" id="emailed" class="mf-select">
                        <option value="">Any</option>
                        <option value="0" @selected(request('emailed') === '0')>Not yet sent</option>
                        <option value="1" @selected(request('emailed') === '1')>Sent</option>
                    </select>
                </div>
                <div>
                    <label for="status" class="mf-label">Status</label>
                    <select name="status" id="status" class="mf-select">
                        <option value="">Issued only</option>
                        <option value="void" @selected(request('status') === 'void')>Void</option>
                    </select>
                </div>
                <button type="submit" class="mf-btn-secondary">Filter</button>
                <a href="{{ route('invoicing.invoices.index') }}" class="mf-link text-[12.5px]">Clear</a>
            </form>
        </div>

        <div class="mf-panel">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="mf-th">Invoice</th>
                        <th class="mf-th">Customer</th>
                        <th class="mf-th">Issued</th>
                        <th class="mf-th">Due</th>
                        <th class="mf-th text-right">Total</th>
                        <th class="mf-th text-right">Balance</th>
                        <th class="mf-th">State</th>
                        <th class="mf-th">Emailed</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($invoices as $invoice)
                        <tr class="mf-tr-divide">
                            <td class="mf-td">
                                <a href="{{ route('invoicing.invoices.show', $invoice) }}" class="mf-link font-mono">{{ $invoice->number }}</a>
                                @if($invoice->isConsolidated())
                                    <span class="mf-tag mf-tag-info ml-1">Consolidated</span>
                                @endif
                            </td>
                            <td class="mf-td">{{ $invoice->customer?->name }}</td>
                            <td class="mf-td">{{ $invoice->issued_at?->format('d/m/Y') }}</td>
                            <td class="mf-td">{{ $invoice->due_date?->format('d/m/Y') }}</td>
                            <td class="mf-td text-right font-mono">€{{ number_format((float) $invoice->total, 2) }}</td>
                            <td class="mf-td text-right font-mono">€{{ number_format((float) $invoice->balance, 2) }}</td>
                            <td class="mf-td">
                                <span class="mf-tag mf-tag-{{ $invoice->payment_state_tone }}">{{ $invoice->payment_state_label }}</span>
                            </td>
                            <td class="mf-td">
                                @if($invoice->hasBeenEmailed())
                                    <span class="text-[12px]" style="color: var(--muted);">{{ $invoice->emailed_at->format('d/m/Y') }}</span>
                                @elseif($invoice->customer && ! $invoice->customer->wantsEmailedInvoices())
                                    <span class="mf-tag mf-tag-neutral">{{ $invoice->customer->invoice_delivery->label() }}</span>
                                @else
                                    <span class="mf-tag mf-tag-warn">Not sent</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="mf-td text-center py-6" style="color: var(--muted);">
                                No invoices match these filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $invoices->links() }}</div>
    </div>
</x-app-layout>
