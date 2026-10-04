<x-app-layout>
    <x-slot name="header">Invoice {{ $invoice->number }}</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium font-mono" style="letter-spacing: -0.4px;">{{ $invoice->number }}</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                    <a href="{{ route('customers.show', $invoice->customer) }}" class="mf-link">{{ $invoice->customer?->name }}</a>
                    · issued {{ $invoice->issued_at?->format('j M Y') }}
                    · due {{ $invoice->due_date?->format('j M Y') }}
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('invoicing.invoices.pdf', $invoice) }}" target="_blank" class="mf-btn-secondary">View PDF</a>
                <a href="{{ route('invoicing.invoices.pdf', ['invoice' => $invoice, 'download' => 1]) }}" class="mf-btn-secondary">Download</a>
                @if($invoice->isIssued())
                    @if($invoice->documentRecipient())
                        <form method="POST" action="{{ route('invoicing.invoices.email', $invoice) }}"
                              onsubmit="return confirm('Email invoice {{ $invoice->number }} to {{ $invoice->documentRecipient() }}?')">
                            @csrf
                            <button type="submit" class="mf-btn-primary">
                                {{ $invoice->hasBeenEmailed() ? 'Email again' : 'Email invoice' }}
                            </button>
                        </form>
                    @endif
                    @if($invoice->canBeReminded())
                        <form method="POST" action="{{ route('invoicing.invoices.remind', $invoice) }}"
                              onsubmit="return confirm('Send a payment reminder for {{ $invoice->number }}?')">
                            @csrf
                            <button type="submit" class="mf-btn-secondary">Send reminder</button>
                        </form>
                    @endif
                    <a href="{{ route('invoicing.credit-notes.create', $invoice) }}" class="mf-btn-secondary">Credit note</a>
                    @can('void', $invoice)
                        <button type="button" class="mf-btn-danger"
                                x-data @click="$dispatch('open-modal', 'void-invoice')">Void</button>
                    @endcan
                @endif
            </div>
        </div>


        @if($invoice->isVoid())
            <div class="mf-flash mf-flash-error">
                <div>
                    <b>Voided {{ $invoice->voided_at?->format('j M Y') }}.</b>
                    {{ $invoice->void_reason }}
                    Its orders went back to the uninvoiced queue.
                </div>
            </div>
        @endif

        @if($invoice->isOverdue())
            <div class="mf-flash mf-flash-warn">
                <div><b>Overdue by {{ $invoice->days_overdue }} {{ \Illuminate\Support\Str::plural('day', $invoice->days_overdue) }}.</b>
                    €{{ number_format((float) $invoice->balance, 2) }} outstanding.
                    @if($invoice->last_reminded_at)
                        Last reminded {{ $invoice->last_reminded_at->format('j M Y') }}.
                    @endif
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4">
            <div>
                <div class="mf-panel mb-4">
                    <div class="mf-panel-header">
                        <div class="text-[13px] font-semibold">
                            Lines
                            @if($invoice->isConsolidated() && $invoice->period_start)
                                <span style="color: var(--muted); font-weight: 400;">
                                    · {{ $invoice->period_start->format('j M') }} – {{ $invoice->period_end->format('j M Y') }}
                                </span>
                            @endif
                        </div>
                    </div>
                    <table class="w-full">
                        <thead>
                            <tr>
                                <th class="mf-th">Description</th>
                                <th class="mf-th text-right">Qty</th>
                                <th class="mf-th text-right">Unit price</th>
                                <th class="mf-th text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $currentOrder = null; @endphp
                            @foreach($invoice->lines as $line)
                                @if($invoice->isConsolidated() && $line->order_id !== $currentOrder)
                                    @php $currentOrder = $line->order_id; $ord = $orders->get($line->order_id); @endphp
                                    <tr>
                                        <td colspan="4" class="px-3 py-1.5 text-[11.5px] uppercase"
                                            style="background: var(--surface-2, #f4f5f2); color: var(--muted); letter-spacing: 0.4px;">
                                            @if($ord)
                                                <a href="{{ route('orders.show', $ord) }}" class="mf-link">{{ $ord->order_number }}</a>
                                                · {{ $ord->billing_date?->format('d/m/Y') }}
                                            @else
                                                Order removed
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                                <tr class="mf-tr-divide">
                                    <td class="mf-td">
                                        {{ $line->description }}
                                        @if(filled($line->detail))
                                            <div class="text-[11.5px]" style="color: var(--warn-ink, #8a6d3b);">{{ $line->detail }}</div>
                                        @endif
                                        @if(! empty($line->batch_codes))
                                            <div class="text-[11px] font-mono" style="color: var(--muted);">Batch: {{ implode(', ', $line->batch_codes) }}</div>
                                        @endif
                                    </td>
                                    <td class="mf-td text-right font-mono">{{ $line->quantity_label ?: '—' }}</td>
                                    <td class="mf-td text-right font-mono">{{ $line->unit_price_label ?: '—' }}</td>
                                    <td class="mf-td text-right font-mono">€{{ number_format((float) $line->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($invoice->allocations->isNotEmpty())
                    <div class="mf-panel mb-4">
                        <div class="mf-panel-header"><div class="text-[13px] font-semibold">Payments</div></div>
                        <table class="w-full">
                            <thead>
                                <tr>
                                    <th class="mf-th">Date</th>
                                    <th class="mf-th">Method</th>
                                    <th class="mf-th">Reference</th>
                                    <th class="mf-th text-right">Applied</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($invoice->allocations as $allocation)
                                    <tr class="mf-tr-divide">
                                        <td class="mf-td">
                                            <a href="{{ route('invoicing.payments.show', $allocation->payment) }}" class="mf-link">
                                                {{ $allocation->payment?->received_at?->format('d/m/Y') }}
                                            </a>
                                        </td>
                                        <td class="mf-td">{{ $allocation->payment?->method_label }}</td>
                                        <td class="mf-td font-mono text-[12px]">{{ $allocation->payment?->reference ?: '—' }}</td>
                                        <td class="mf-td text-right font-mono">€{{ number_format((float) $allocation->amount, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if($invoice->creditNotes->isNotEmpty())
                    <div class="mf-panel mb-4">
                        <div class="mf-panel-header"><div class="text-[13px] font-semibold">Credit notes</div></div>
                        <table class="w-full">
                            <thead>
                                <tr>
                                    <th class="mf-th">Number</th>
                                    <th class="mf-th">Date</th>
                                    <th class="mf-th">Reason</th>
                                    <th class="mf-th text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($invoice->creditNotes as $note)
                                    <tr class="mf-tr-divide">
                                        <td class="mf-td">
                                            <a href="{{ route('invoicing.credit-notes.show', $note) }}" class="mf-link font-mono">{{ $note->number }}</a>
                                            @if($note->isVoid())<span class="mf-tag mf-tag-neutral ml-1">Void</span>@endif
                                        </td>
                                        <td class="mf-td">{{ $note->issued_at?->format('d/m/Y') }}</td>
                                        <td class="mf-td">{{ $note->reason }}</td>
                                        <td class="mf-td text-right font-mono">€{{ number_format((float) $note->total, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="mf-panel">
                    <div class="mf-panel-header"><div class="text-[13px] font-semibold">Email history</div></div>
                    @if($invoice->emails->isEmpty())
                        <div class="px-4 py-4 text-[13px]" style="color: var(--muted);">This invoice hasn't been emailed yet.</div>
                    @else
                        <table class="w-full">
                            <thead>
                                <tr>
                                    <th class="mf-th">When</th>
                                    <th class="mf-th">Type</th>
                                    <th class="mf-th">To</th>
                                    <th class="mf-th">By</th>
                                    <th class="mf-th">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($invoice->emails as $email)
                                    <tr class="mf-tr-divide">
                                        <td class="mf-td">{{ ($email->sent_at ?? $email->queued_at)?->format('d/m/Y H:i') }}</td>
                                        <td class="mf-td">{{ $email->kind->label() }}</td>
                                        <td class="mf-td text-[12px]">{{ $email->recipient }}</td>
                                        <td class="mf-td text-[12px]">{{ $email->sender?->name ?? '—' }}</td>
                                        <td class="mf-td">
                                            <span class="mf-tag mf-tag-{{ $email->status_tone }}">{{ $email->status->label() }}</span>
                                            @if($email->error)
                                                <div class="text-[11px] mt-0.5" style="color: var(--danger);">{{ $email->error }}</div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>

            <div>
                <div class="mf-panel mb-4">
                    <div class="mf-panel-header"><div class="text-[13px] font-semibold">Summary</div></div>
                    <dl class="px-4 py-3 text-[13px] grid grid-cols-[1fr_auto] gap-y-2">
                        <dt style="color: var(--muted);">Subtotal</dt>
                        <dd class="font-mono text-right">€{{ number_format((float) $invoice->subtotal, 2) }}</dd>
                        @if((float) $invoice->delivery_charge_net > 0)
                            <dt style="color: var(--muted);">Delivery charge</dt>
                            <dd class="font-mono text-right">€{{ number_format((float) $invoice->delivery_charge_net, 2) }}</dd>
                        @endif
                        <dt style="color: var(--muted);">{{ (float) $invoice->delivery_charge_net > 0 ? 'VAT (23%)' : 'Tax' }}</dt>
                        <dd class="font-mono text-right">€{{ number_format((float) $invoice->tax_amount, 2) }}</dd>
                        <dt class="font-semibold pt-2" style="border-top: 1px solid var(--line-2);">Total</dt>
                        <dd class="font-mono text-right font-semibold pt-2" style="border-top: 1px solid var(--line-2);">€{{ number_format((float) $invoice->total, 2) }}</dd>
                        @if((float) $invoice->amount_paid > 0)
                            <dt style="color: var(--muted);">Paid</dt>
                            <dd class="font-mono text-right">−€{{ number_format((float) $invoice->amount_paid, 2) }}</dd>
                        @endif
                        @if((float) $invoice->amount_credited > 0)
                            <dt style="color: var(--muted);">Credited</dt>
                            <dd class="font-mono text-right">−€{{ number_format((float) $invoice->amount_credited, 2) }}</dd>
                        @endif
                        <dt class="font-semibold pt-2" style="border-top: 1px solid var(--line-2);">Balance due</dt>
                        <dd class="font-mono text-right font-semibold pt-2" style="border-top: 1px solid var(--line-2);">€{{ number_format((float) $invoice->balance, 2) }}</dd>
                    </dl>
                </div>

                <div class="mf-panel mb-4">
                    <div class="mf-panel-header"><div class="text-[13px] font-semibold">Billed to</div></div>
                    <div class="px-4 py-3 text-[13px]">
                        <div class="font-semibold">{{ $invoice->bill_to_name }}</div>
                        @if(filled($invoice->bill_to_address))
                            <div class="whitespace-pre-line" style="color: var(--muted);">{{ $invoice->bill_to_address }}</div>
                        @endif
                        @if(filled($invoice->bill_to_email))
                            <div class="mt-1">{{ $invoice->bill_to_email }}</div>
                        @endif
                        @if(filled($invoice->bill_to_vat_number))
                            <div class="text-[12px]" style="color: var(--muted);">VAT {{ $invoice->bill_to_vat_number }}</div>
                        @endif
                    </div>
                </div>

                @if((float) $invoice->balance > 0)
                    <a href="{{ route('invoicing.payments.create', ['customer_id' => $invoice->customer_id]) }}" class="mf-btn-primary w-full justify-center">
                        Record a payment
                    </a>
                @endif
            </div>
        </div>
    </div>

    @can('void', $invoice)
        <x-modal name="void-invoice" focusable>
            <form method="POST" action="{{ route('invoicing.invoices.void', $invoice) }}" class="p-6">
                @csrf
                <h2 class="text-[15px] font-semibold">Void invoice {{ $invoice->number }}?</h2>
                <p class="mt-2 text-[13px]" style="color: var(--muted);">
                    The invoice stays on file marked void, and its orders return to the uninvoiced
                    queue so they can be billed again. The number is not reused.
                </p>
                <div class="mt-4">
                    <label for="void_reason" class="mf-label">Reason</label>
                    <input id="void_reason" name="void_reason" type="text" class="mf-input" required maxlength="255"
                           placeholder="e.g. wrong customer, priced before the weights were corrected">
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="mf-btn-secondary" x-on:click="$dispatch('close')">Cancel</button>
                    <button type="submit" class="mf-btn-danger">Void invoice</button>
                </div>
            </form>
        </x-modal>
    @endcan
</x-app-layout>
