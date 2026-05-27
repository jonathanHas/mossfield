@props([
    'label',
    'total',          // total bottles
    'caseSize',       // bottles per case
    'segments',       // ['available' => n, 'allocated' => n, ...] in bottles
    'expiry' => null, // Carbon|null
    'expiryWarn' => false,
    'batchCode' => null,
])

@php
    $caseSize = max(1, (int) $caseSize);
    $totalCases = (int) ceil($total / $caseSize);
    $perRow = min(max($totalCases, 1), 30);

    $stateOrder = ['available', 'allocated', 'reserved', 'damaged', 'sold'];
    $blocks = [];
    $caseCounts = [];
    foreach ($stateOrder as $state) {
        $bottleCount = (int) ($segments[$state] ?? 0);
        $caseCount = (int) round($bottleCount / $caseSize);
        $caseCounts[$state] = ['cases' => $caseCount, 'bottles' => $bottleCount];
        for ($i = 0; $i < $caseCount; $i++) {
            $blocks[] = $state;
        }
    }
    while (count($blocks) < $totalCases) {
        $blocks[] = 'empty';
    }
    if (count($blocks) > $totalCases) {
        $blocks = array_slice($blocks, 0, $totalCases);
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
            {{ number_format($total) }} bottles
        </div>
        <div class="text-[11px] font-mono" style="color: var(--muted);">
            {{ $totalCases }} cases × {{ $caseSize }}
        </div>
    </div>

    <div class="flex-1 min-w-0">
        <div class="stock-case-grid" style="grid-template-columns: repeat({{ $perRow }}, minmax(0, 1fr)); max-width: 520px;">
            @foreach($blocks as $i => $state)
                @php
                    $tooltip = $stateLabel($state).' · '.($caseCounts[$state]['bottles'] ?? 0).' bottles · '.($caseCounts[$state]['cases'] ?? 0).' cases';
                    $bgVar = $state === 'empty' ? null : '--state-'.$state;
                @endphp
                <div
                    class="stock-case @if($state === 'empty') stock-case--empty @endif"
                    @if($bgVar) style="background: var({{ $bgVar }});" @endif
                    title="{{ $tooltip }}"
                ></div>
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
