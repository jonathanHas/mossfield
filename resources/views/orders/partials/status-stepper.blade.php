@php
    /** @var \App\Models\Order $order */
    $steps = [
        ['id' => 'pending',    'name' => 'Pending'],
        ['id' => 'confirmed',  'name' => 'Confirmed'],
        ['id' => 'preparing',  'name' => 'Picking'],
        ['id' => 'ready',      'name' => 'Ready'],
        ['id' => 'dispatched', 'name' => 'Dispatched'],
        ['id' => 'delivered',  'name' => 'Delivered'],
    ];

    $statusIndex = array_search($order->status, array_column($steps, 'id'), true);
@endphp

@if($order->status !== 'cancelled')
    <div class="mf-pstepper" role="list" aria-label="Order progress">
        @foreach($steps as $i => $step)
            @php
                if ($statusIndex === false) {
                    $state = 'pending';
                } elseif ($i < $statusIndex) {
                    $state = 'done';
                } elseif ($i === $statusIndex) {
                    $state = 'current';
                } else {
                    $state = 'pending';
                }
            @endphp
            <div class="mf-pstep is-{{ $state }}" role="listitem" aria-current="{{ $state === 'current' ? 'step' : 'false' }}">
                <div class="mf-pstep-node"></div>
                <div class="mf-pstep-label">{{ $step['name'] }}</div>
                {{-- "Picking" alone reads as under way the moment stock is
                     allocated; say how much has actually been picked. Sums
                     the loaded lines — no query. --}}
                @if($step['id'] === 'preparing' && $order->status === 'preparing')
                    <div style="font-size: 11px; color: var(--muted);" data-picked-count>
                        {{ $order->orderItems->sum('quantity_fulfilled') }} of {{ $order->orderItems->sum('quantity_ordered') }} picked
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
