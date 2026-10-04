{{-- Credit note against an issued invoice. Amounts print positive; the
     document type is what makes them a deduction. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Credit note {{ $creditNote->number }}</title>
    @include('documents._pdf-styles')
</head>
<body>
    @unless($pdf ?? false)
        <div class="toolbar">
            <a href="{{ route('invoicing.credit-notes.pdf', ['credit_note' => $creditNote, 'download' => 1]) }}">Download PDF</a>
            <button type="button" class="primary" onclick="window.print()">Print</button>
        </div>
    @endunless

    @if($creditNote->isVoid())
        <div class="void-stamp">VOID</div>
    @endif

    @include('documents._pdf-header', [
        'title' => 'Credit note',
        'number' => $creditNote->number,
        'flag' => $creditNote->isVoid() ? 'VOID' : null,
    ])

    <table class="meta">
        <tr>
            <td style="padding-right: 20px;">
                <div class="block-label">Credit to</div>
                <div class="block-body">
                    <strong>{{ $creditNote->invoice?->bill_to_name ?? $creditNote->customer?->name }}</strong><br>
                    @if(filled($creditNote->invoice?->bill_to_address))
                        {!! nl2br(e($creditNote->invoice->bill_to_address)) !!}<br>
                    @endif
                    @if(filled($creditNote->invoice?->bill_to_vat_number))
                        VAT {{ $creditNote->invoice->bill_to_vat_number }}
                    @endif
                </div>
            </td>
            <td style="padding-left: 20px;">
                <div class="block-label">Credit note details</div>
                <div class="block-body">
                    Date: {{ $creditNote->issued_at?->format('d/m/Y') }}<br>
                    Against invoice: {{ $creditNote->invoice?->number }}<br>
                    @if($creditNote->invoice?->issued_at)
                        Invoice date: {{ $creditNote->invoice->issued_at->format('d/m/Y') }}<br>
                    @endif
                    Reason: {{ $creditNote->reason }}
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th style="text-align: right; width: 70px;">Qty</th>
                <th style="text-align: right; width: 90px;">VAT</th>
                <th style="text-align: right; width: 100px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($creditNote->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="num">{{ (float) $line->quantity > 0 ? rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') : '—' }}</td>
                    <td class="num">{{ (float) $line->vat_rate > 0 ? rtrim(rtrim(number_format((float) $line->vat_rate, 2), '0'), '.').'%' : '—' }}</td>
                    <td class="num">€{{ number_format((float) $line->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">Subtotal</td>
            <td class="val">€{{ number_format((float) $creditNote->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td class="label">VAT</td>
            <td class="val">€{{ number_format((float) $creditNote->tax_amount, 2) }}</td>
        </tr>
        <tr class="grand">
            <td class="label">Total credited</td>
            <td class="val">€{{ number_format((float) $creditNote->total, 2) }}</td>
        </tr>
    </table>

    @include('documents._seller-footer', ['showPayment' => false])
</body>
</html>
