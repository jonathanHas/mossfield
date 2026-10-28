<x-app-layout>
    <x-slot name="header">Credit note</x-slot>

    <div class="px-6 py-5">
        <div class="mb-4">
            <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">
                Credit note against {{ $invoice->number }}
            </h1>
            <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                {{ $invoice->customer?->name }} · up to €{{ number_format($creditable, 2) }} can still be credited.
                Enter the amount to credit on each line and leave the others blank.
            </div>
        </div>


        <form method="POST" action="{{ route('invoicing.credit-notes.store', $invoice) }}"
              x-data="creditNoteForm({{ $creditable }}, @js(array_map(fn ($l) => (float) $l['vat_rate'], $suggested)), @js(array_map(fn ($i) => (string) old("lines.$i.amount", ''), array_keys($suggested))))">
            @csrf

            <div class="mf-panel mb-4">
                <div class="mf-panel-header"><div class="text-[13px] font-semibold">What is being credited</div></div>
                <table class="w-full">
                    <thead>
                        <tr>
                            <th class="mf-th">Description</th>
                            <th class="mf-th text-right">Qty</th>
                            <th class="mf-th text-right">VAT %</th>
                            <th class="mf-th text-right" style="width: 150px;">Credit (€)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($suggested as $index => $line)
                            <tr class="mf-tr-divide">
                                <td class="mf-td">
                                    <input type="hidden" name="lines[{{ $index }}][invoice_line_id]" value="{{ $line['invoice_line_id'] }}">
                                    <input type="text" name="lines[{{ $index }}][description]" class="mf-input"
                                           value="{{ $line['description'] }}" maxlength="255" required>
                                </td>
                                <td class="mf-td text-right">
                                    <input type="number" step="0.001" min="0" name="lines[{{ $index }}][quantity]"
                                           class="mf-input text-right font-mono" style="width: 90px;"
                                           value="{{ $line['quantity'] }}">
                                </td>
                                <td class="mf-td text-right">
                                    <input type="number" step="0.01" min="0" max="100" name="lines[{{ $index }}][vat_rate]"
                                           class="mf-input text-right font-mono" style="width: 80px;"
                                           x-model.number="rates[{{ $index }}]">
                                </td>
                                <td class="mf-td text-right">
                                    <input type="number" step="0.01" min="0" name="lines[{{ $index }}][amount]"
                                           class="mf-input text-right font-mono" style="width: 130px;"
                                           x-model.number="amounts[{{ $index }}]">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="mf-td text-right" style="color: var(--muted);">Total to credit (incl. VAT)</td>
                            <td class="mf-td text-right font-mono font-semibold"
                                :style="over() ? 'color: var(--danger);' : ''"
                                x-text="'€' + total().toFixed(2)"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="mf-panel mb-4">
                <div class="px-4 py-3">
                    <label for="reason" class="mf-label">Reason</label>
                    <input type="text" id="reason" name="reason" class="mf-input" required maxlength="255"
                           value="{{ old('reason') }}"
                           placeholder="e.g. two tubs returned damaged, priced at the old rate">
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="mf-btn-primary" :disabled="over() || total() <= 0">Issue credit note</button>
                <a href="{{ route('invoicing.invoices.show', $invoice) }}" class="mf-btn-secondary">Cancel</a>
                <div class="text-[12.5px]" style="color: var(--danger);" x-show="over()" x-cloak>
                    That's more than the €{{ number_format($creditable, 2) }} still creditable on this invoice.
                </div>
            </div>
        </form>

        <script>
            /* Live total so the operator sees the credit build up, and can't
               submit more than the invoice has left to give. The state is
               seeded here, not from value= (x-model blanks an input whose
               model starts undefined): VAT from the invoice line, amounts
               blank unless a refused submit is handing them back. */
            function creditNoteForm(creditable, rates, amounts) {
                return {
                    creditable,
                    amounts: { ...amounts },
                    rates: { ...rates },
                    total() {
                        return Object.keys(this.amounts).reduce((sum, key) => {
                            const amount = parseFloat(this.amounts[key]) || 0;
                            const rate = parseFloat(this.rates[key]) || 0;
                            return sum + amount + (amount * rate / 100);
                        }, 0);
                    },
                    over() {
                        return this.total() > this.creditable + 0.001;
                    },
                };
            }
        </script>
    </div>
</x-app-layout>
