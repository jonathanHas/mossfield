<x-app-layout>
    <x-slot name="header">Record a payment</x-slot>

    <div class="px-6 py-5">
        <div class="mb-4">
            <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Record a payment</h1>
            <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                Money is recorded against the customer, then applied to whichever invoices it settles.
                Anything left over stays on account as credit.
            </div>
        </div>

        @include('invoicing.partials._nav')

        {{-- Step one: pick the customer. The page reloads so the allocation
             table can be built from their real open balances. --}}
        <div class="mf-panel mb-4">
            <form method="GET" class="flex flex-wrap gap-3 items-end p-4">
                <div>
                    <label for="customer_id" class="mf-label">Customer</label>
                    <select name="customer_id" id="customer_id" class="mf-select" onchange="this.form.submit()">
                        <option value="">Choose a customer…</option>
                        @foreach($customers as $option)
                            <option value="{{ $option->id }}" @selected($customer && $customer->id === $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </div>
                <noscript><button type="submit" class="mf-btn-secondary">Continue</button></noscript>
            </form>
        </div>

        @if($customer)
            @php
                $invoiceData = $openInvoices->map(fn ($i) => [
                    'id' => $i->id,
                    'number' => $i->number,
                    'balance' => (float) $i->balance,
                ])->values();
                $credit = $customer->unallocatedCredit();
            @endphp

            <form method="POST" action="{{ route('invoicing.payments.store') }}"
                  x-data="paymentForm({{ Illuminate\Support\Js::from($invoiceData) }}, @js((string) old('amount', '')), @js((object) array_filter((array) old('allocations', []), fn ($v) => $v !== null && $v !== '')))">
                @csrf
                <input type="hidden" name="customer_id" value="{{ $customer->id }}">
                <input type="hidden" name="form_token" value="{{ old('form_token', $formToken) }}">

                <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4">
                    <div class="mf-panel">
                        <div class="mf-panel-header"><div class="text-[13px] font-semibold">Apply to invoices</div></div>

                        @if($openInvoices->isEmpty())
                            <div class="px-4 py-5 text-[13px]" style="color: var(--muted);">
                                {{ $customer->name }} has no open invoices. The payment will sit on account as credit.
                            </div>
                        @else
                            <table class="w-full">
                                <thead>
                                    <tr>
                                        <th class="mf-th">Invoice</th>
                                        <th class="mf-th">Issued</th>
                                        <th class="mf-th">Due</th>
                                        <th class="mf-th text-right">Outstanding</th>
                                        <th class="mf-th text-right" style="width: 140px;">Apply</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($openInvoices as $invoice)
                                        <tr class="mf-tr-divide">
                                            <td class="mf-td">
                                                <a href="{{ route('invoicing.invoices.show', $invoice) }}" class="mf-link font-mono">{{ $invoice->number }}</a>
                                            </td>
                                            <td class="mf-td">{{ $invoice->issued_at?->format('d/m/Y') }}</td>
                                            <td class="mf-td">
                                                {{ $invoice->due_date?->format('d/m/Y') }}
                                                @if($invoice->isOverdue())
                                                    <span class="mf-tag mf-tag-danger ml-1">{{ $invoice->days_overdue }}d</span>
                                                @endif
                                            </td>
                                            <td class="mf-td text-right font-mono">€{{ number_format((float) $invoice->balance, 2) }}</td>
                                            <td class="mf-td text-right">
                                                <input type="number" step="0.01" min="0"
                                                       max="{{ (float) $invoice->balance }}"
                                                       name="allocations[{{ $invoice->id }}]"
                                                       x-model="allocations[{{ $invoice->id }}]"
                                                       x-on:input="allocationsTouched = true"
                                                       class="mf-input text-right font-mono" style="width: 120px;"
                                                       placeholder="0.00">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="4" class="mf-td text-right" style="color: var(--muted);">Allocated</td>
                                        <td class="mf-td text-right font-mono" x-text="'€' + allocated().toFixed(2)"></td>
                                    </tr>
                                    <tr>
                                        <td colspan="4" class="mf-td text-right" style="color: var(--muted);">Left on account</td>
                                        <td class="mf-td text-right font-mono"
                                            :style="unallocated() < 0 ? 'color: var(--danger);' : ''"
                                            x-text="'€' + unallocated().toFixed(2)"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        @endif
                    </div>

                    <div>
                        <div class="mf-panel mb-4">
                            <div class="mf-panel-header"><div class="text-[13px] font-semibold">Payment</div></div>
                            <div class="px-4 py-3 space-y-3">
                                <div>
                                    <label for="amount" class="mf-label">Amount received (€)</label>
                                    {{-- A string model, parsed where used: x-model.number turned a
                                         typed "-5" into 5 (E2E F-40). min="0.01" now refuses it. --}}
                                    <input type="number" step="0.01" min="0.01" id="amount" name="amount"
                                           x-model="amount" class="mf-input font-mono" required>
                                </div>
                                <div>
                                    <label for="received_at" class="mf-label">Date received</label>
                                    <input type="date" id="received_at" name="received_at" class="mf-input"
                                           value="{{ old('received_at', today()->toDateString()) }}" required>
                                </div>
                                <div>
                                    <label for="method" class="mf-label">Method</label>
                                    <select id="method" name="method" class="mf-select" required>
                                        @foreach($methods as $method)
                                            <option value="{{ $method->value }}" @selected(old('method') === $method->value)>{{ $method->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="reference" class="mf-label">Reference</label>
                                    <input type="text" id="reference" name="reference" class="mf-input"
                                           value="{{ old('reference') }}" maxlength="255"
                                           placeholder="Bank reference, cheque no…">
                                </div>
                                <div>
                                    <label for="notes" class="mf-label">Notes</label>
                                    <textarea id="notes" name="notes" class="mf-textarea" rows="2">{{ old('notes') }}</textarea>
                                </div>
                            </div>
                        </div>

                        @if($credit > 0)
                            <div class="mf-flash mf-flash-warn">
                                <div>{{ $customer->name }} already has €{{ number_format($credit, 2) }} of unapplied credit on account.</div>
                            </div>
                        @endif

                        <div class="flex flex-col gap-2">
                            @if($openInvoices->isNotEmpty())
                                <button type="button" class="mf-btn-secondary justify-center" @click="autoFill()">
                                    Auto-fill oldest first
                                </button>
                            @endif
                            <button type="submit" class="mf-btn-primary justify-center" :disabled="!valid()">
                                Record payment
                            </button>
                            <div class="text-[12px] text-center" style="color: var(--danger);" x-show="unallocated() < 0" x-cloak>
                                Allocated more than the payment.
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <script>
                /* Keeps the allocation table honest against the amount entered:
                   the running totals update live and Record stays disabled while
                   the split exceeds the payment. Unless the operator has edited an
                   Apply box, the amount is applied oldest-first as it's typed, so
                   a payment isn't left sitting as credit by default (E2E F-36). */
                function paymentForm(invoices, initialAmount, initialAllocations) {
                    // After a refused submit, old('allocations') is the operator's
                    // own split — keep it, and keep auto-fill off it.
                    const restored = Object.keys(initialAllocations || {}).length > 0;
                    return {
                        invoices,
                        amount: initialAmount ?? '',
                        allocations: restored ? { ...initialAllocations } : {},
                        allocationsTouched: restored,
                        init() {
                            this.$watch('amount', () => {
                                if (! this.allocationsTouched) {
                                    this.autoFill();
                                }
                            });
                        },
                        allocated() {
                            return Object.values(this.allocations)
                                .reduce((sum, v) => sum + (parseFloat(v) || 0), 0);
                        },
                        unallocated() {
                            return (parseFloat(this.amount) || 0) - this.allocated();
                        },
                        valid() {
                            return (parseFloat(this.amount) || 0) > 0 && this.unallocated() >= -0.001;
                        },
                        autoFill() {
                            this.allocationsTouched = false;
                            let left = parseFloat(this.amount) || 0;
                            this.allocations = {};
                            for (const invoice of this.invoices) {
                                if (left <= 0) break;
                                const take = Math.min(left, invoice.balance);
                                if (take > 0) {
                                    this.allocations[invoice.id] = take.toFixed(2);
                                    left = Math.round((left - take) * 100) / 100;
                                }
                            }
                        },
                    };
                }
            </script>
        @endif
    </div>
</x-app-layout>
