{{-- Payment reminder for an invoice past its due date. Deliberately warm and
     short: it goes to shops we deliver to every week. --}}
@php
    $seller = config('invoicing.seller.name');
    $bank = config('invoicing.bank');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $seller }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f5f2; font-family: Arial, Helvetica, sans-serif; color:#2b2b2b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f2; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px; width:100%; background:#ffffff; border:1px solid #e2e4de; border-radius:8px; overflow:hidden;">
                    <tr>
                        <td style="background:#2f4a2f; padding:20px 28px;">
                            <div style="color:#ffffff; font-size:18px; font-weight:bold;">{{ $seller }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 16px; font-size:15px;">Dear {{ $invoice->customer?->name ?? 'customer' }},</p>

                            <p style="margin:0 0 16px; font-size:15px; line-height:1.5;">
                                This is a friendly reminder that invoice <strong>{{ $invoice->number }}</strong> is now
                                {{ $invoice->days_overdue }} {{ \Illuminate\Support\Str::plural('day', $invoice->days_overdue) }} past its due date.
                                A copy is attached for your convenience.
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px; font-size:14px;">
                                <tr>
                                    <td style="padding:2px 16px 2px 0; color:#555;">Invoice total</td>
                                    <td style="padding:2px 0;">€{{ number_format((float) $invoice->total, 2) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:2px 16px 2px 0; color:#555;">Balance outstanding</td>
                                    <td style="padding:2px 0; font-weight:bold;">€{{ number_format((float) $invoice->balance, 2) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:2px 16px 2px 0; color:#555;">Due date</td>
                                    <td style="padding:2px 0;">{{ $invoice->due_date?->format('j M Y') }}</td>
                                </tr>
                            </table>

                            @if(filled($bank['iban'] ?? null))
                                <p style="margin:0 0 16px; font-size:14px; line-height:1.5; color:#555;">
                                    Payment can be made by transfer to IBAN <strong>{{ $bank['iban'] }}</strong>@if(filled($bank['bic'] ?? null)) (BIC {{ $bank['bic'] }})@endif,
                                    quoting reference <strong>{{ $invoice->number }}</strong>.
                                </p>
                            @endif

                            <p style="margin:0 0 16px; font-size:15px; line-height:1.5;">
                                If you've already sent this payment, or if there's a query on the invoice, just reply to
                                this email and we'll sort it out.
                            </p>

                            <p style="margin:24px 0 0; font-size:15px;">
                                Kind regards,<br>
                                The {{ $seller }} team
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px; border-top:1px solid #e2e4de; font-size:12px; color:#8a8f84;">
                            This is an automated message from {{ $seller }}.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
