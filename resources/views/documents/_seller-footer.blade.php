{{-- Seller identity + how to pay. Everything comes from config/invoicing.php,
     so a farm with no VAT number or bank details simply prints neither. --}}
@php
    $seller = config('invoicing.seller');
    $bank = config('invoicing.bank');
    $hasBank = filled($bank['iban'] ?? null) || filled($bank['name'] ?? null);
    $showPay = ($showPayment ?? true) && $hasBank;
@endphp

@if($showPay)
    <div class="pay-details">
        <div class="block-label">How to pay</div>
        <table>
            @if(filled($bank['name'] ?? null))
                <tr><td class="k">Bank</td><td class="v">{{ $bank['name'] }}</td></tr>
            @endif
            @if(filled($bank['iban'] ?? null))
                <tr><td class="k">IBAN</td><td class="v">{{ $bank['iban'] }}</td></tr>
            @endif
            @if(filled($bank['bic'] ?? null))
                <tr><td class="k">BIC</td><td class="v">{{ $bank['bic'] }}</td></tr>
            @endif
            @if(filled($reference ?? null))
                <tr><td class="k">Reference</td><td class="v">{{ $reference }}</td></tr>
            @endif
        </table>
    </div>
@endif

<div class="footer">
    {{ $seller['name'] }}@foreach($seller['address_lines'] as $line) · {{ $line }}@endforeach
    @if(filled($seller['vat_number'])) · VAT {{ $seller['vat_number'] }}@endif
    @if(filled($seller['email'])) · {{ $seller['email'] }}@endif
    @if(filled($seller['phone'])) · {{ $seller['phone'] }}@endif
    <br>Generated {{ now()->format('d/m/Y H:i') }}
</div>
