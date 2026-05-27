@props([
    'label',
    'total',          // total tubs
    'caseSize',       // tubs per case (typically 6 for yoghurt)
    'segments',
    'expiry' => null,
    'expiryWarn' => false,
    'batchCode' => null,
])

@php
    $caseSize = max(1, (int) $caseSize);
    $totalCases = (int) ceil($total / $caseSize);

    $stateOrder = ['available', 'allocated', 'reserved', 'damaged', 'sold'];
    $icons = [];
    $caseCounts = [];
    foreach ($stateOrder as $state) {
        $tubCount = (int) ($segments[$state] ?? 0);
        $caseCount = (int) round($tubCount / $caseSize);
        $caseCounts[$state] = ['cases' => $caseCount, 'tubs' => $tubCount];
        for ($i = 0; $i < $caseCount; $i++) {
            $icons[] = $state;
        }
    }

    $stateLabel = fn ($s) => ucfirst($s);
@endphp

<div class="flex flex-col lg:flex-row lg:items-center gap-4">
    <div class="lg:w-[120px] flex-shrink-0">
        <div class="text-[13.5px] font-semibold">{{ $label }}</div>
        @if($batchCode)
            <div class="text-[11px] font-mono mt-0.5" style="color: var(--ink);">{{ $batchCode }}</div>
        @endif
        <div class="text-[11px] font-mono mt-0.5" style="color: var(--muted);">
            {{ number_format($total) }} tubs
        </div>
        <div class="text-[11px] font-mono" style="color: var(--muted);">
            {{ count($icons) }} cases × {{ $caseSize }}
        </div>
    </div>

    <div class="flex-1 min-w-0">
        <div class="stock-pictograph" style="max-width: 520px;">
            @foreach($icons as $state)
                @php
                    $tooltip = $stateLabel($state).' · '.($caseCounts[$state]['tubs'] ?? 0).' tubs · '.($caseCounts[$state]['cases'] ?? 0).' cases';
                @endphp
                <svg width="18" height="15" viewBox="0 0 18 16" title="{{ $tooltip }}" style="display: block;">
                    <title>{{ $tooltip }}</title>
                    <path d="M2 4 h14 l-1.5 11 c-0.1 0.7 -0.5 1 -1.2 1 h-8.6 c-0.7 0 -1.1 -0.3 -1.2 -1 z"
                        fill="var(--state-{{ $state }})" stroke="rgba(0,0,0,0.15)" stroke-width="0.6" />
                    <ellipse cx="9" cy="3.5" rx="7.4" ry="1.8"
                        fill="var(--state-{{ $state }})" stroke="rgba(0,0,0,0.18)" stroke-width="0.6" />
                    <ellipse cx="9" cy="3.5" rx="6" ry="1.1" fill="rgba(255,255,255,0.4)" />
                </svg>
            @endforeach
        </div>
    </div>

    <div class="stock-segments flex-shrink-0">
        @foreach(['available', 'allocated', 'sold'] as $s)
            @if(($segments[$s] ?? 0) > 0 || $s === 'available')
                <div>
                    <div class="stock-segments__label">{{ $s }}</div>
                    <div class="stock-segments__value" style="color: var(--state-{{ $s }});">
                        {{ number_format($segments[$s] ?? 0) }}
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    @if($expiry)
        <x-stock.tag :tone="$expiryWarn ? 'warn' : 'neutral'">
            Exp {{ $expiry->format('d/m') }}
        </x-stock.tag>
    @endif
</div>
