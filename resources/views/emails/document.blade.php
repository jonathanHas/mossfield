{{-- Generic covering note for an emailed finance document. Same shell as
     emails/order-document.blade.php so everything the customer receives from
     us looks like it came from the same place. --}}
@php
    $seller = config('invoicing.seller.name');
    $isInvoice = $kind === \App\Enums\DocumentEmailKind::Invoice;
    $isCredit = $kind === \App\Enums\DocumentEmailKind::CreditNote;
    $label = strtolower($kind->label());
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
                            <p style="margin:0 0 16px; font-size:15px;">Dear {{ $customer?->name ?? 'customer' }},</p>

                            @if($isInvoice)
                                <p style="margin:0 0 16px; font-size:15px; line-height:1.5;">
                                    Please find attached invoice <strong>{{ $document->number }}</strong>@if($document->isConsolidated() && $document->period_start) covering deliveries from {{ $document->period_start->format('j M Y') }} to {{ $document->period_end->format('j M Y') }}@endif.
                                </p>
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px; font-size:14px;">
                                    <tr>
                                        <td style="padding:2px 16px 2px 0; color:#555;">Amount due</td>
                                        <td style="padding:2px 0; font-weight:bold;">€{{ number_format((float) $document->total, 2) }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:2px 16px 2px 0; color:#555;">Due date</td>
                                        <td style="padding:2px 0;">{{ $document->due_date?->format('j M Y') }}</td>
                                    </tr>
                                </table>
                            @elseif($isCredit)
                                <p style="margin:0 0 16px; font-size:15px; line-height:1.5;">
                                    Please find attached credit note <strong>{{ $document->number }}</strong> for
                                    <strong>€{{ number_format((float) $document->total, 2) }}</strong>, raised against
                                    invoice {{ $document->invoice?->number }}.
                                </p>
                            @else
                                <p style="margin:0 0 16px; font-size:15px; line-height:1.5;">
                                    Please find attached your statement of account to
                                    <strong>{{ $document->period_end?->format('j M Y') }}</strong>, showing a balance of
                                    <strong>€{{ number_format((float) $document->closing_balance, 2) }}</strong>.
                                </p>
                            @endif

                            <p style="margin:0 0 16px; font-size:15px; line-height:1.5;">
                                If you have any questions about this {{ $label }}, just reply to this email and we'll be glad to help.
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
