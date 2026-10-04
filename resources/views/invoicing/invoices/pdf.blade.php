{{-- Rendered ONLY from the invoice snapshot (header columns + invoice_lines).
     Never reach through to $invoice->orders for figures: those keep changing
     after issue, which is the whole reason the snapshot exists. --}}
@php
    $isPreview = $invoice->number === 'PREVIEW';
    $termLabels = config('invoicing.payment_terms_labels');
    $lines = $invoice->lines;
    // Consolidated invoices group their lines under a per-order heading; a
    // per-order invoice is one flat list with no headings.
    $grouped = $invoice->isConsolidated();
    // Both kinds need it: consolidated invoices for their per-order headings,
    // per-order invoices for the "Order … · Your ref …" detail line (E2E F-35).
    $orderLookup = \App\Models\Order::whereIn('id', $lines->pluck('order_id')->filter()->unique())->get()->keyBy('id');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    @include('documents._pdf-styles')
</head>
<body>
    @unless($pdf ?? false)
        <div class="toolbar">
            @unless($isPreview)
                <a href="{{ route('invoicing.invoices.pdf', ['invoice' => $invoice, 'download' => 1]) }}">Download PDF</a>
            @endunless
            <button type="button" class="primary" onclick="window.print()">Print</button>
        </div>
    @endunless

    @if($invoice->isVoid())
        <div class="void-stamp">VOID</div>
    @endif

    @include('documents._pdf-header', [
        'title' => 'Invoice',
        'number' => $isPreview ? null : $invoice->number,
        'flag' => $isPreview ? 'PREVIEW — NOT ISSUED' : ($invoice->isVoid() ? 'VOID' : null),
    ])

    <table class="meta">
        <tr>
            <td style="padding-right: 20px;">
                <div class="block-label">Bill to</div>
                <div class="block-body">
                    <strong>{{ $invoice->bill_to_name }}</strong><br>
                    @if(filled($invoice->bill_to_address))
                        {!! nl2br(e($invoice->bill_to_address)) !!}<br>
                    @endif
                    @if(filled($invoice->bill_to_email))
                        {{ $invoice->bill_to_email }}<br>
                    @endif
                    @if(filled($invoice->bill_to_vat_number))
                        VAT {{ $invoice->bill_to_vat_number }}
                    @endif
                </div>
            </td>
            <td style="padding-left: 20px;">
                <div class="block-label">Invoice details</div>
                <div class="block-body">
                    Invoice date: {{ $invoice->issued_at?->format('d/m/Y') }}<br>
                    @unless($grouped)
                        {{-- The customer's PO reference is what their accounts team
                             matches the invoice against. Consolidated invoices show
                             it in each order heading instead. --}}
                        @foreach($orderLookup as $ord)
                            Order {{ $ord->order_number }}@if(filled($ord->customer_reference)) · Your ref: {{ $ord->customer_reference }}@endif<br>
                        @endforeach
                    @endunless
                    @if($invoice->isConsolidated() && $invoice->period_start)
                        Period: {{ $invoice->period_start->format('d/m/Y') }} – {{ $invoice->period_end->format('d/m/Y') }}<br>
                    @endif
                    Payment terms: {{ $termLabels[$invoice->payment_terms] ?? ucfirst((string) $invoice->payment_terms) }}<br>
                    Due date: {{ $invoice->due_date?->format('d/m/Y') }}
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th style="text-align: right; width: 70px;">Qty</th>
                <th style="text-align: right; width: 110px;">Unit price</th>
                <th style="text-align: right; width: 100px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @php $currentOrder = null; @endphp
            @forelse($lines as $line)
                @if($grouped && $line->order_id !== $currentOrder)
                    @php
                        $currentOrder = $line->order_id;
                        $ord = $orderLookup->get($line->order_id);
                    @endphp
                    <tr class="group">
                        <td colspan="4">
                            @if($ord)
                                Order {{ $ord->order_number }}
                                @if($ord->billing_date) · {{ $ord->billing_date->format('d/m/Y') }} @endif
                                @if(filled($ord->customer_reference)) · ref {{ $ord->customer_reference }} @endif
                            @else
                                Order
                            @endif
                        </td>
                    </tr>
                @endif
                <tr>
                    <td>
                        {{ $line->description }}
                        @if(filled($line->detail))
                            <div class="short-note">{{ $line->detail }}</div>
                        @endif
                        @if(! empty($line->batch_codes))
                            <div class="batch">Batch: {{ implode(', ', $line->batch_codes) }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $line->quantity_label }}</td>
                    <td class="num">{{ $line->unit_price_label ?: '—' }}</td>
                    <td class="num">€{{ number_format((float) $line->amount, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="muted" style="text-align: center; padding: 16px;">No items on this invoice.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">Subtotal</td>
            <td class="val">€{{ number_format((float) $invoice->subtotal, 2) }}</td>
        </tr>
        @if((float) $invoice->delivery_charge_net > 0)
            <tr>
                <td class="label">Delivery charge</td>
                <td class="val">€{{ number_format((float) $invoice->delivery_charge_net, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">{{ (float) $invoice->delivery_charge_net > 0 ? 'VAT (23%)' : 'Tax' }}</td>
            <td class="val">€{{ number_format((float) $invoice->tax_amount, 2) }}</td>
        </tr>
        <tr class="grand">
            <td class="label">Total</td>
            <td class="val">€{{ number_format((float) $invoice->total, 2) }}</td>
        </tr>
        @if(! $isPreview && ((float) $invoice->amount_paid > 0 || (float) $invoice->amount_credited > 0))
            @if((float) $invoice->amount_paid > 0)
                <tr>
                    <td class="label">Paid</td>
                    <td class="val">−€{{ number_format((float) $invoice->amount_paid, 2) }}</td>
                </tr>
            @endif
            @if((float) $invoice->amount_credited > 0)
                <tr>
                    <td class="label">Credited</td>
                    <td class="val">−€{{ number_format((float) $invoice->amount_credited, 2) }}</td>
                </tr>
            @endif
            <tr class="due">
                <td class="label">Balance due</td>
                <td class="val">€{{ number_format((float) $invoice->balance, 2) }}</td>
            </tr>
        @endif
    </table>

    @if(filled($invoice->notes))
        <div class="notes">
            <div class="block-label">Notes</div>
            <div>{!! nl2br(e($invoice->notes)) !!}</div>
        </div>
    @endif

    @include('documents._seller-footer', [
        'reference' => $isPreview ? null : $invoice->number,
        'showPayment' => ! $invoice->isVoid(),
    ])
</body>
</html>
