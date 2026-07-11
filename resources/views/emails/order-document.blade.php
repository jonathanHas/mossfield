@php
    $label = $document === 'invoice' ? 'invoice' : 'dispatch docket';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mossfield Organic Farm</title>
</head>
<body style="margin:0; padding:0; background:#f4f5f2; font-family: Arial, Helvetica, sans-serif; color:#2b2b2b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f2; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px; width:100%; background:#ffffff; border:1px solid #e2e4de; border-radius:8px; overflow:hidden;">
                    <tr>
                        <td style="background:#2f4a2f; padding:20px 28px;">
                            <div style="color:#ffffff; font-size:18px; font-weight:bold;">Mossfield Organic Farm</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 16px; font-size:15px;">Dear {{ $order->customer->name }},</p>

                            <p style="margin:0 0 16px; font-size:15px; line-height:1.5;">
                                Please find attached your {{ $label }} for order
                                <strong>{{ $order->order_number }}</strong>@if($order->delivery_date) (delivery {{ $order->delivery_date->format('j M Y') }})@endif.
                            </p>

                            @if($order->customer_reference)
                                <p style="margin:0 0 16px; font-size:14px; color:#555;">
                                    Your reference: <strong>{{ $order->customer_reference }}</strong>
                                </p>
                            @endif

                            <p style="margin:0 0 16px; font-size:15px; line-height:1.5;">
                                If you have any questions about this {{ $label }}, just reply to this email and we'll be glad to help.
                            </p>

                            <p style="margin:24px 0 0; font-size:15px;">
                                Kind regards,<br>
                                The Mossfield Organic Farm team
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px; border-top:1px solid #e2e4de; font-size:12px; color:#8a8f84;">
                            This is an automated message from Mossfield Organic Farm.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
