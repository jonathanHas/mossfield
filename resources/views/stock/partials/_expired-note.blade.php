{{-- Expired stock the card above leaves out: active batches past expiry with
     units left (StockOverviewService::expiredByType()). --}}
@if ($expired['batches'] > 0)
    <div class="text-[12px] {{ $class ?? '' }}" style="color: var(--muted);" data-expired-note>
        {{ $expired['batches'] }} expired {{ \Illuminate\Support\Str::plural('batch', $expired['batches']) }}
        still {{ $expired['batches'] === 1 ? 'holds' : 'hold' }} {{ number_format($expired['units']) }} {{ \Illuminate\Support\Str::plural('unit', $expired['units']) }} —
        <a href="{{ route('batches.index', ['status' => 'expired']) }}" class="underline">see Batches</a>.
    </div>
@endif
