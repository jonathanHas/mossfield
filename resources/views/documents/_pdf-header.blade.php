{{-- Brand block + document title. $title is the document name ("Invoice"),
     $number its reference, $flag an optional red line ("PRO FORMA", "VOID"). --}}
@php
    $sellerName = config('invoicing.seller.name');
@endphp
<div class="header">
    <table>
        <tr>
            <td style="vertical-align: top;">
                <div class="brand">{{ $sellerName }}</div>
                <div class="brand-sub">{{ $title }}</div>
            </td>
            <td style="vertical-align: top;">
                <div class="doc-title">{{ $title }}</div>
                @if(filled($number ?? null))
                    <div class="doc-num">{{ $number }}</div>
                @endif
                @if(filled($flag ?? null))
                    <div class="doc-flag">{{ $flag }}</div>
                @endif
            </td>
        </tr>
    </table>
</div>
