{{-- DISPATCH CONFIRMATION — the priced picture of what went on the van.

     Emailed automatically at dispatch (App\Documents\DispatchConfirmation) so
     the shop can check the delivery against the money straight away. It shows
     quantities, weights, batch codes and value but carries NO invoice number and
     no due date: the numbered invoice is a separate stored snapshot, sent from
     Finance once the office is happy. This page used to be the "pro forma"
     invoice and, before that, invented an INV- number on every render. --}}
@php
    $termLabels = config('invoicing.payment_terms_labels');
    $terms = $order->customer->payment_terms;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Dispatch confirmation {{ $order->order_number }}</title>
    @include('documents._pdf-styles')
</head>
<body>
    @unless($pdf ?? false)
        <div class="toolbar">
            <a href="{{ route('orders.dispatch-confirmation', ['order' => $order, 'download' => 1]) }}">Download PDF</a>
            <button type="button" class="primary" onclick="window.print()">Print</button>
        </div>
    @endunless

    @include('documents._pdf-header', [
        'title' => 'Dispatch confirmation',
        'number' => $order->order_number,
        'flag' => 'INVOICE TO FOLLOW',
    ])

    <table class="meta">
        <tr>
            <td style="padding-right: 20px;">
                <div class="block-label">Customer</div>
                <div class="block-body">
                    <strong>{{ $order->customer->name }}</strong><br>
                    {!! nl2br(e($order->customer->full_address)) !!}
                    @if(filled($order->customer->email))
                        <br>{{ $order->customer->email }}
                    @endif
                </div>
            </td>
            <td style="padding-left: 20px;">
                <div class="block-label">Order details</div>
                <div class="block-body">
                    Order no: {{ $order->order_number }}<br>
                    Dispatched: {{ $order->dispatched_at?->format('d/m/Y') ?? '—' }}<br>
                    @if($order->delivery_date)
                        Delivery: {{ $order->delivery_date->format('d/m/Y') }}<br>
                    @endif
                    @if(filled($order->customer_reference))
                        Customer ref: {{ $order->customer_reference }}<br>
                    @endif
                    Payment terms: {{ $termLabels[$terms] ?? ucfirst((string) $terms) }}
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
            @forelse($order->orderItems as $item)
                <tr>
                    <td>
                        {{ $item->productVariant->full_name }}
                        @if($item->isPricedByWeight() && $item->weight_fulfilled_kg > 0)
                            <span class="muted">({{ number_format($item->weight_fulfilled_kg, 3) }} kg)</span>
                        @endif
                        @if(count($item->batch_codes))
                            <div class="batch">Batch: {{ implode(', ', $item->batch_codes) }}</div>
                        @endif
                        @if($item->isShortClosed())
                            {{-- The line stays even at €0.00: the customer ordered it and
                                 needs to see it was acknowledged and not charged, and it
                                 has to agree with the docket, which shows it. --}}
                            <div class="short-note">
                                @if($item->quantity_fulfilled === 0)
                                    Not supplied — not charged.
                                @else
                                    {{ $item->quantity_short }} of {{ $item->quantity_ordered }} not supplied — not charged.
                                @endif
                            </div>
                        @endif
                    </td>
                    <td class="num">{{ $item->invoiceable_quantity }}</td>
                    <td class="num">{{ $item->unit_price_label }}</td>
                    <td class="num">€{{ number_format($item->invoiceable_total, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="muted" style="text-align: center; padding: 16px;">No items on this order.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">Subtotal</td>
            <td class="val">€{{ number_format($order->subtotal, 2) }}</td>
        </tr>
        @if($order->delivery_charge > 0)
            <tr>
                <td class="label">{{ $order->delivery_charge_percent ? 'Delivery charge ('.rtrim(rtrim(number_format($order->delivery_charge_percent, 2), '0'), '.').'%)' : 'Delivery charge' }}</td>
                <td class="val">€{{ number_format($order->delivery_charge_net, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">{{ $order->delivery_charge > 0 ? 'VAT (23%)' : 'Tax' }}</td>
            <td class="val">€{{ number_format($order->tax_amount, 2) }}</td>
        </tr>
        <tr class="grand">
            <td class="label">Total</td>
            <td class="val">€{{ number_format($order->total_amount, 2) }}</td>
        </tr>
    </table>

    @if(filled($order->notes))
        <div class="notes">
            <div class="block-label">Notes</div>
            <div>{!! nl2br(e($order->notes)) !!}</div>
        </div>
    @endif

    @include('documents._seller-footer', ['showPayment' => false])
</body>
</html>
