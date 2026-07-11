<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Dispatch Docket {{ $order->order_number }}</title>
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
            <a href="{{ route('orders.docket', ['order' => $order, 'download' => 1]) }}">Download PDF</a>
            <button type="button" class="primary" onclick="window.print()">Print</button>
        </div>
    @endunless
    <div class="header">
        <table>
            <tr>
                <td style="vertical-align: top;">
                    <div class="brand">Mossfield Organic Farm</div>
                    <div class="brand-sub">Dispatch Docket</div>
                </td>
                <td style="vertical-align: top;">
                    <div class="doc-title">Docket</div>
                    <div class="doc-num">{{ $order->order_number }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="meta">
        <tr>
            <td style="padding-right: 20px;">
                <div class="block-label">Deliver to</div>
                <div class="block-body">
                    <strong>{{ $order->customer->name }}</strong><br>
                    @php
                        $deliverTo = filled($order->delivery_address)
                            ? $order->delivery_address
                            : $order->customer->full_address;
                    @endphp
                    {!! nl2br(e($deliverTo)) !!}
                </div>
            </td>
            <td style="padding-left: 20px;">
                <div class="block-label">Order details</div>
                <div class="block-body">
                    Order date: {{ $order->order_date->format('d/m/Y') }}<br>
                    Delivery date: {{ $order->delivery_date ? $order->delivery_date->format('d/m/Y') : '—' }}<br>
                    @if(filled($order->customer_reference))
                        Customer ref: {{ $order->customer_reference }}<br>
                    @endif
                    Status: {{ ucfirst($order->status) }}
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Product</th>
                <th style="text-align: right; width: 90px;">Ordered</th>
                <th style="text-align: right; width: 90px;">Picked</th>
                <th style="text-align: right; width: 100px;">Weight (kg)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($order->orderItems as $item)
                <tr>
                    <td>
                        {{ $item->productVariant->full_name }}
                        @if(count($item->batch_codes))
                            <div class="batch">Batch: {{ implode(', ', $item->batch_codes) }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $item->quantity_ordered }}</td>
                    <td class="num">{{ $item->quantity_fulfilled }}</td>
                    <td class="num">
                        @if($item->isVariableWeight() && $item->weight_fulfilled_kg)
                            {{ number_format($item->weight_fulfilled_kg, 3) }}
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="muted" style="text-align: center; padding: 16px;">No items on this order.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if(filled($order->notes))
        <div class="notes">
            <div class="block-label">Notes</div>
            <div>{!! nl2br(e($order->notes)) !!}</div>
        </div>
    @endif

    <div class="footer">
        Generated {{ now()->format('d/m/Y H:i') }} · Mossfield Organic Farm
    </div>
</body>
</html>
