@props([
    'label',
    'total',
    'segments',  // associative array of state => count, in render order
    'batchCode' => null,
])

@php
    // Render order matches batches/partials/batch-card.blade.php so colors line up:
    // available (yellow→green), allocated (amber/warn), maturing (deep amber),
    // cut (grey), converted-to-mature (purple), sold (black).
    $order = ['available', 'allocated', 'maturing', 'cut', 'converted', 'sold'];
    $dots = [];
    foreach ($order as $state) {
        $n = (int) ($segments[$state] ?? 0);
        for ($i = 0; $i < $n; $i++) {
            $dots[] = $state;
        }
    }
    $stateLabel = fn ($s) => ucfirst($s);
@endphp

<div class="flex flex-col lg:flex-row lg:items-center gap-4">
    <div class="lg:w-[180px] flex-shrink-0">
        <div class="text-[13.5px] font-semibold">{{ $label }}</div>
        @if($batchCode)
            <div class="text-[11px] font-mono mt-0.5" style="color: var(--ink);">{{ $batchCode }}</div>
        @endif
        <div class="text-[11px] font-mono mt-0.5" style="color: var(--muted);">
            {{ number_format($total) }} {{ Str::plural('unit', $total) }}
        </div>
    </div>

    <div class="flex-1 min-w-0">
        <div class="stock-cheese-row" style="max-width: 520px;">
            @foreach($dots as $state)
                <span class="stock-cheese-dot"
                      style="background: var(--state-{{ $state }});"
                      title="{{ $stateLabel($state) }}"></span>
            @endforeach
        </div>
    </div>

    <div class="stock-segments flex-shrink-0 flex-wrap">
        @foreach($order as $s)
            @if(($segments[$s] ?? 0) > 0)
                <div>
                    <div class="stock-segments__label">{{ $s }}</div>
                    <div class="stock-segments__value" style="color: var(--state-{{ $s }});">
                        {{ number_format($segments[$s]) }}
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</div>
