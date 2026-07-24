<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $order->order_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #1a1a1a;
            font-size: 12px;
            line-height: 1.45;
            margin: 0;
            padding: 32px 36px;
        }
        .header {
            border-bottom: 2px solid #1a1a1a;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .header table { width: 100%; border-collapse: collapse; }
        .brand { font-size: 20px; font-weight: bold; letter-spacing: 0.3px; }
        .brand-sub { font-size: 11px; color: #666; margin-top: 2px; }
        .doc-title { font-size: 16px; font-weight: bold; text-align: right; }
        .doc-num { font-family: 'DejaVu Sans Mono', monospace; font-size: 12px; text-align: right; color: #444; margin-top: 2px; }

        .meta { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        .meta td { vertical-align: top; width: 50%; padding: 0; }
        .block-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.6px; color: #888; margin-bottom: 4px; }
        .block-body { font-size: 12px; }
        .block-body strong { font-size: 13px; }

        table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.items th {
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #555;
            border-bottom: 1.5px solid #1a1a1a;
            padding: 6px 8px;
        }
        table.items td {
            padding: 7px 8px;
            border-bottom: 1px solid #ddd;
            font-size: 12px;
        }
        table.items .num { text-align: right; font-family: 'DejaVu Sans Mono', monospace; }
        .muted { color: #999; }
        .batch { font-size: 10px; color: #666; margin-top: 2px; font-family: 'DejaVu Sans Mono', monospace; }

        table.totals { margin-top: 14px; margin-left: auto; border-collapse: collapse; }
        table.totals td { padding: 4px 8px; font-size: 12px; }
        table.totals td.label { color: #666; text-align: right; }
        table.totals td.val { text-align: right; font-family: 'DejaVu Sans Mono', monospace; min-width: 100px; }
        table.totals tr.grand td { font-size: 14px; font-weight: bold; border-top: 1.5px solid #1a1a1a; padding-top: 8px; }

        .notes { margin-top: 22px; padding: 10px 12px; background: #f6f6f4; border: 1px solid #e2e2dd; }
        .notes .block-label { margin-bottom: 3px; }

        .footer { margin-top: 34px; font-size: 10px; color: #999; border-top: 1px solid #ddd; padding-top: 8px; }

        .toolbar {
            display: flex; gap: 8px; justify-content: flex-end;
            margin: -12px -12px 20px; padding: 10px 12px;
            background: #f6f6f4; border: 1px solid #e2e2dd; border-radius: 6px;
        }
        .toolbar a, .toolbar button {
            font: inherit; font-size: 12px; cursor: pointer;
            border: 1px solid #1a1a1a; border-radius: 5px;
            padding: 6px 14px; text-decoration: none; color: #1a1a1a; background: #fff;
        }
        .toolbar button.primary { background: #1a1a1a; color: #fff; }
        @media print { .toolbar { display: none !important; } }
    </style>
</head>
<body>
    @unless($pdf ?? false)
        <div class="toolbar">
            <a href="{{ route('orders.invoice', ['order' => $order, 'download' => 1]) }}">Download PDF</a>
            <button type="button" class="primary" onclick="window.print()">Print</button>
        </div>
    @endunless

    @php
        $issueDate = now();
        $termLabels = [
            'immediate' => 'Immediate',
            'net_7' => 'Net 7 days',
            'net_14' => 'Net 14 days',
            'net_30' => 'Net 30 days',
        ];
        $termDays = ['immediate' => 0, 'net_7' => 7, 'net_14' => 14, 'net_30' => 30];
        $terms = $order->customer->payment_terms;
        $dueDate = $issueDate->copy()->addDays($termDays[$terms] ?? 0);
        $invoiceNumber = str_replace('ORD-', 'INV-', $order->order_number);
    @endphp

    <div class="header">
        <table>
            <tr>
                <td style="vertical-align: top;">
                    <div class="brand">Mossfield Organic Farm</div>
                    <div class="brand-sub">Invoice</div>
                </td>
                <td style="vertical-align: top;">
                    <div class="doc-title">Invoice</div>
                    <div class="doc-num">{{ $invoiceNumber }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="meta">
        <tr>
            <td style="padding-right: 20px;">
                <div class="block-label">Bill to</div>
                <div class="block-body">
                    <strong>{{ $order->customer->name }}</strong><br>
                    {!! nl2br(e($order->customer->full_address)) !!}
                    @if(filled($order->customer->email))
                        <br>{{ $order->customer->email }}
                    @endif
                </div>
            </td>
            <td style="padding-left: 20px;">
                <div class="block-label">Invoice details</div>
                <div class="block-body">
                    Invoice date: {{ $issueDate->format('d/m/Y') }}<br>
                    Order no: {{ $order->order_number }}<br>
                    @if(filled($order->customer_reference))
                        Customer ref: {{ $order->customer_reference }}<br>
                    @endif
                    Payment terms: {{ $termLabels[$terms] ?? ucfirst((string) $terms) }}<br>
                    Due date: {{ $dueDate->format('d/m/Y') }}
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
                    </td>
                    <td class="num">{{ $item->quantity_ordered }}</td>
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

    <div class="footer">
        Generated {{ $issueDate->format('d/m/Y H:i') }} · Mossfield Organic Farm
    </div>
</body>
</html>
