{{-- Statement of account, rendered from the stored JSON ledger snapshot rather
     than re-queried, so a re-print matches what the customer was sent. --}}
@php
    $lines = $statement->lines ?? [];
    $aged = $statement->aged ?? [];
    $typeLabels = ['invoice' => 'Invoice', 'credit_note' => 'Credit note', 'payment' => 'Payment'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement {{ $statement->period_end?->format('Y-m-d') }}</title>
    @include('documents._pdf-styles')
</head>
<body>
    @unless($pdf ?? false)
        <div class="toolbar">
            <a href="{{ route('invoicing.statements.pdf', ['statement' => $statement, 'download' => 1]) }}">Download PDF</a>
            <button type="button" class="primary" onclick="window.print()">Print</button>
        </div>
    @endunless

    @include('documents._pdf-header', [
        'title' => 'Statement of account',
        'number' => null,
        'flag' => null,
    ])

    <table class="meta">
        <tr>
            <td style="padding-right: 20px;">
                <div class="block-label">Account</div>
                <div class="block-body">
                    <strong>{{ $statement->customer?->name }}</strong><br>
                    @if(filled($statement->customer?->full_address))
                        {!! nl2br(e(implode("\n", array_filter([
                            $statement->customer->address,
                            $statement->customer->city,
                            $statement->customer->postal_code,
                            $statement->customer->country,
                        ], fn ($p) => filled($p))))) !!}
                    @endif
                </div>
            </td>
            <td style="padding-left: 20px;">
                <div class="block-label">Statement details</div>
                <div class="block-body">
                    Period: {{ $statement->period_start?->format('d/m/Y') }} – {{ $statement->period_end?->format('d/m/Y') }}<br>
                    Statement date: {{ $statement->generated_at?->format('d/m/Y') }}<br>
                    Opening balance: €{{ number_format((float) $statement->opening_balance, 2) }}
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 80px;">Date</th>
                <th style="width: 100px;">Reference</th>
                <th>Description</th>
                <th style="text-align: right; width: 85px;">Charges</th>
                <th style="text-align: right; width: 85px;">Payments</th>
                <th style="text-align: right; width: 90px;">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="5" class="muted">Balance brought forward</td>
                <td class="num">€{{ number_format((float) $statement->opening_balance, 2) }}</td>
            </tr>
            @forelse($lines as $line)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($line['date'])->format('d/m/Y') }}</td>
                    <td style="font-family: 'DejaVu Sans Mono', monospace; font-size: 11px;">{{ $line['reference'] }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td class="num">{{ $line['debit'] > 0 ? '€'.number_format($line['debit'], 2) : '' }}</td>
                    <td class="num">{{ $line['credit'] > 0 ? '€'.number_format($line['credit'], 2) : '' }}</td>
                    <td class="num">€{{ number_format($line['balance'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="muted" style="text-align: center; padding: 16px;">
                        No activity in this period.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr class="grand">
            <td class="label">Balance due</td>
            <td class="val">€{{ number_format((float) $statement->closing_balance, 2) }}</td>
        </tr>
    </table>

    @if($aged)
        <div class="block-label" style="margin-top: 20px;">Aged breakdown</div>
        <table class="aged">
            <thead>
                <tr>
                    <th>Not yet due</th>
                    <th>1–30 days</th>
                    <th>31–60 days</th>
                    <th>61–90 days</th>
                    <th>90+ days</th>
                    @if(($aged['credit'] ?? 0) > 0)<th>Credit</th>@endif
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>€{{ number_format($aged['current'] ?? 0, 2) }}</td>
                    <td>€{{ number_format($aged['days_1_30'] ?? 0, 2) }}</td>
                    <td>€{{ number_format($aged['days_31_60'] ?? 0, 2) }}</td>
                    <td>€{{ number_format($aged['days_61_90'] ?? 0, 2) }}</td>
                    <td>€{{ number_format($aged['days_90_plus'] ?? 0, 2) }}</td>
                    @if(($aged['credit'] ?? 0) > 0)<td>€{{ number_format($aged['credit'], 2) }}</td>@endif
                </tr>
            </tbody>
        </table>
    @endif

    @include('documents._seller-footer', ['showPayment' => (float) $statement->closing_balance > 0])
</body>
</html>
