<x-app-layout>
    <x-slot name="header">Allocate {{ $order->order_number }}</x-slot>

    @php
        $statusTone = match($order->status) {
            'pending' => 'warn',
            'confirmed', 'preparing' => 'info',
            'ready', 'dispatched', 'delivered' => 'accent',
            'cancelled' => 'danger',
            default => 'neutral',
        };
    @endphp

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <div class="text-[12px] font-mono" style="color: var(--muted);">Allocation</div>
                <h1 class="mt-0.5 text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">
                    Order <span class="font-mono">{{ $order->order_number }}</span>
                </h1>
                <div class="mt-1 flex items-center gap-2">
                    <span class="mf-tag mf-tag-{{ $statusTone }}">{{ ucfirst($order->status) }}</span>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('order-allocations.index') }}" class="mf-btn-ghost">← Back</a>
                <form method="POST" action="{{ route('order-allocations.auto-allocate', $order) }}" class="inline">
                    @csrf
                    <button type="submit" class="mf-btn-primary"
                        onclick="return confirm('Auto-allocate available stock to this order using FIFO?')">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                        Auto allocate
                    </button>
                </form>
            </div>
        </div>

        <div class="mf-panel mb-4">
            <dl class="px-4 py-3 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-2 text-[13px]">
                <div>
                    <dt class="text-[11.5px] uppercase font-medium" style="color: var(--muted); letter-spacing: 0.4px;">Customer</dt>
                    <dd class="mt-0.5 font-medium">{{ $order->customer->name }}</dd>
                </div>
                <div>
                    <dt class="text-[11.5px] uppercase font-medium" style="color: var(--muted); letter-spacing: 0.4px;">Order date</dt>
                    <dd class="mt-0.5 font-mono">{{ $order->order_date->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="text-[11.5px] uppercase font-medium" style="color: var(--muted); letter-spacing: 0.4px;">Status</dt>
                    <dd class="mt-0.5"><span class="mf-tag mf-tag-{{ $statusTone }}">{{ ucfirst($order->status) }}</span></dd>
                </div>
                <div>
                    <dt class="text-[11.5px] uppercase font-medium" style="color: var(--muted); letter-spacing: 0.4px;">Total</dt>
                    <dd class="mt-0.5 font-mono font-medium">€{{ number_format($order->total_amount, 2) }}</dd>
                </div>
            </dl>
        </div>

        @foreach($order->orderItems as $orderItem)
            @php
                $allocationPercentage = $orderItem->quantity_ordered > 0 ?
                    ($orderItem->quantity_allocated / $orderItem->quantity_ordered) * 100 : 0;
            @endphp

            <div class="mf-panel mb-4">
                <div class="px-4 py-3" style="border-bottom: 1px solid var(--line-2); background: var(--bg);">
                    <div class="flex justify-between items-start gap-4">
                        <div class="flex items-start gap-3 flex-1 min-w-0">
                            @if($orderItem->productVariant->display_image_url)
                                <img src="{{ $orderItem->productVariant->display_image_url }}"
                                     alt="{{ $orderItem->productVariant->product->name }}"
                                     class="h-14 w-14 object-cover rounded flex-shrink-0" style="border: 1px solid var(--line);" />
                            @endif
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h3 class="text-[15px] font-semibold">{{ $orderItem->productVariant->product->name }}</h3>
                                    @if($orderItem->isVariableWeight())
                                        <span class="mf-tag mf-tag-warn">Variable weight</span>
                                    @endif
                                </div>
                                <p class="text-[12.5px] mt-0.5" style="color: var(--muted);">{{ $orderItem->productVariant->name }}</p>
                                <p class="text-[12px] mt-1 font-mono" style="color: var(--muted);">
                                    Ordered: <span style="color: var(--ink);">{{ $orderItem->quantity_ordered }}</span> ·
                                    Allocated: <span style="color: var(--ink);">{{ $orderItem->quantity_allocated }}</span> ·
                                    Fulfilled: <span style="color: var(--ink);">{{ $orderItem->quantity_fulfilled }}</span>
                                    @if($orderItem->isVariableWeight() && $orderItem->weight_fulfilled_kg)
                                        ({{ number_format($orderItem->weight_fulfilled_kg, 3) }} kg)
                                    @endif
                                </p>
                            </div>
                        </div>
                        <div class="text-right flex-shrink-0">
                            @if($orderItem->isPricedByWeight())
                                <div class="text-[12px]" style="color: var(--muted);">{{ $orderItem->productVariant->price_label }}</div>
                                @if($orderItem->fulfilled_total)
                                    <div class="text-[16px] font-mono font-medium" style="color: var(--accent-ink);">€{{ number_format($orderItem->fulfilled_total, 2) }}</div>
                                    <div class="text-[11px]" style="color: var(--faint);">Fulfilled value</div>
                                @else
                                    <div class="text-[16px] font-mono font-medium" style="color: var(--faint);">€{{ number_format($orderItem->line_total, 2) }}</div>
                                    <div class="text-[11px]" style="color: var(--faint);">Estimated</div>
                                @endif
                            @else
                                <div class="text-[16px] font-mono font-medium">€{{ number_format($orderItem->line_total, 2) }}</div>
                            @endif
                            @if($allocationPercentage > 0)
                                <div class="text-[12px] mt-1" style="color: var(--muted);">{{ number_format($allocationPercentage, 0) }}% allocated</div>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3">
                        <div class="flex justify-between text-[12px] mb-1" style="color: var(--muted);">
                            <span>Allocation progress</span>
                            <span class="font-mono">{{ $orderItem->quantity_allocated }}/{{ $orderItem->quantity_ordered }}</span>
                        </div>
                        <div class="w-full rounded-full h-1.5" style="background: var(--line);">
                            <div class="h-1.5 rounded-full" style="background: var(--accent); width: {{ min(100, $allocationPercentage) }}%"></div>
                        </div>
                    </div>
                </div>

                @if($orderItem->orderAllocations->count() > 0)
                    <div class="px-4 py-3" style="border-bottom: 1px solid var(--line-2);">
                        <h4 class="text-[11.5px] font-medium uppercase mb-2" style="color: var(--muted); letter-spacing: 0.4px;">Current allocations</h4>
                        <div class="overflow-x-auto rounded-md" style="border: 1px solid var(--line);">
                            <table class="w-full border-collapse text-[13px]">
                                <thead>
                                    <tr>
                                        <th class="mf-th">Batch</th>
                                        <th class="mf-th">Production</th>
                                        <th class="mf-th text-right">Allocated</th>
                                        <th class="mf-th text-right">Fulfilled</th>
                                        @if($orderItem->isVariableWeight())
                                            <th class="mf-th text-right">Weight</th>
                                        @endif
                                        <th class="mf-th"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($orderItem->orderAllocations as $allocation)
                                        <tr style="border-top: 1px solid var(--line-2);">
                                            <td class="mf-td font-mono">{{ $allocation->batchItem->batch->batch_code }}</td>
                                            <td class="mf-td font-mono" style="color: var(--muted);">{{ $allocation->batchItem->batch->production_date->format('d/m/Y') }}</td>
                                            <td class="mf-td font-mono text-right">{{ $allocation->quantity_allocated }}</td>
                                            <td class="mf-td font-mono text-right">{{ $allocation->quantity_fulfilled }}</td>
                                            @if($orderItem->isVariableWeight())
                                                <td class="mf-td font-mono text-right">
                                                    @if($allocation->actual_weight_kg)
                                                        {{ number_format($allocation->actual_weight_kg, 3) }} kg
                                                    @else
                                                        <span style="color: var(--faint);">—</span>
                                                    @endif
                                                </td>
                                            @endif
                                            <td class="mf-td">
                                                <div class="space-y-2">
                                                    @if($allocation->quantity_remaining > 0)
                                                        @if($orderItem->isVariableWeight())
                                                            @php
                                                                $remainingQty = $allocation->quantity_remaining;
                                                                $expWeight = $allocation->batchItem->unit_weight_kg ?? $orderItem->productVariant->weight_kg ?? 1;
                                                            @endphp
                                                            <form method="POST" action="{{ route('order-allocations.fulfill', $allocation) }}">
                                                                @csrf
                                                                <div class="space-y-2">
                                                                    <div class="flex items-center gap-2">
                                                                        <label class="text-[11.5px]" style="color: var(--muted);">Qty:</label>
                                                                        <select name="quantity" id="qty_{{ $allocation->id }}"
                                                                            class="mf-select w-16 px-1 py-0.5 text-[12px]"
                                                                            onchange="updateWeightInputs({{ $allocation->id }}, this.value, {{ $remainingQty }})">
                                                                            @for($i = 1; $i <= $remainingQty; $i++)
                                                                                <option value="{{ $i }}" {{ $i == $remainingQty ? 'selected' : '' }}>{{ $i }}</option>
                                                                            @endfor
                                                                        </select>
                                                                        <button type="submit" class="mf-link" style="color: var(--accent-ink);">Fulfill</button>
                                                                    </div>
                                                                    <div class="space-y-1" id="weights_{{ $allocation->id }}">
                                                                        @for($i = 1; $i <= $remainingQty; $i++)
                                                                            <div class="flex items-center gap-1 weight-row" data-index="{{ $i }}">
                                                                                <span class="text-[11.5px] w-12" style="color: var(--muted);">#{{ $i }}:</span>
                                                                                <input type="number" name="weights[]"
                                                                                    step="0.001" min="0.001"
                                                                                    placeholder="{{ number_format($expWeight, 3) }}"
                                                                                    class="mf-input w-24 px-2 py-0.5 text-[12px] weight-input"
                                                                                    oninput="updateTotal({{ $allocation->id }})"
                                                                                    required>
                                                                                <span class="text-[11.5px]" style="color: var(--muted);">kg</span>
                                                                            </div>
                                                                        @endfor
                                                                    </div>
                                                                    <div class="text-[11.5px] font-mono" style="color: var(--ink-2);">
                                                                        Total: <span id="total_{{ $allocation->id }}" class="font-medium">0.000</span> kg
                                                                    </div>
                                                                    <input type="hidden" name="actual_weight_kg" id="actual_weight_{{ $allocation->id }}" value="0">
                                                                </div>
                                                            </form>
                                                        @else
                                                            <form method="POST" action="{{ route('order-allocations.fulfill', $allocation) }}" class="inline">
                                                                @csrf
                                                                <div class="flex items-center gap-1">
                                                                    <input type="number" name="quantity"
                                                                        min="1" max="{{ $allocation->quantity_remaining }}"
                                                                        value="{{ $allocation->quantity_remaining }}"
                                                                        class="mf-input w-16 px-2 py-0.5 text-[12px]">
                                                                    <button type="submit" class="mf-link" style="color: var(--accent-ink);">Fulfill</button>
                                                                </div>
                                                            </form>
                                                        @endif
                                                    @endif
                                                    @if($allocation->quantity_fulfilled == 0)
                                                        <form method="POST" action="{{ route('order-allocations.deallocate', $allocation) }}" class="inline">
                                                            @csrf @method('DELETE')
                                                            <button type="submit" class="mf-link" style="color: var(--danger);"
                                                                onclick="return confirm('Remove this allocation?')">Remove</button>
                                                        </form>
                                                    @endif
                                                    @if($allocation->quantity_fulfilled > 0)
                                                        <form method="POST" action="{{ route('order-allocations.unfulfill', $allocation) }}" class="inline">
                                                            @csrf
                                                            <div class="flex items-center gap-1">
                                                                <input type="number" name="quantity"
                                                                    min="1" max="{{ $allocation->quantity_fulfilled }}"
                                                                    value="{{ $allocation->quantity_fulfilled }}"
                                                                    class="mf-input w-16 px-2 py-0.5 text-[12px]">
                                                                <button type="submit" class="mf-link" style="color: var(--warn-ink);"
                                                                    onclick="return confirm('Undo fulfillment? This will restore stock to the batch.')">Undo</button>
                                                            </div>
                                                        </form>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @if($orderItem->quantity_allocated < $orderItem->quantity_ordered && isset($availableBatchItems[$orderItem->id]) && $availableBatchItems[$orderItem->id]->count() > 0)
                    <div class="px-4 py-3">
                        <h4 class="text-[11.5px] font-medium uppercase mb-2" style="color: var(--muted); letter-spacing: 0.4px;">Available stock</h4>
                        <div class="rounded-md p-3" style="background: var(--info-soft); border: 1px solid oklch(0.92 0.04 235);">
                            <form method="POST" action="{{ route('order-allocations.allocate', $orderItem) }}" class="space-y-3">
                                @csrf
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                    <div>
                                        <label class="mf-label">Select batch</label>
                                        <select name="batch_item_id" required class="mf-select">
                                            <option value="">Choose batch…</option>
                                            @foreach($availableBatchItems[$orderItem->id] as $batchItem)
                                                <option value="{{ $batchItem->id }}">
                                                    {{ $batchItem->batch->batch_code }}
                                                    ({{ $batchItem->batch->production_date->format('d/m/Y') }})
                                                    — {{ $batchItem->available_quantity }} available
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mf-label">Quantity to allocate</label>
                                        <input type="number" name="quantity"
                                            min="1"
                                            max="{{ $orderItem->quantity_ordered - $orderItem->quantity_allocated }}"
                                            value="{{ $orderItem->quantity_ordered - $orderItem->quantity_allocated }}"
                                            required class="mf-input">
                                    </div>
                                    <div class="flex items-end">
                                        <button type="submit" class="mf-btn-primary w-full justify-center">Allocate</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                @elseif($orderItem->quantity_allocated < $orderItem->quantity_ordered)
                    <div class="mx-4 mb-4 mf-flash mf-flash-warn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                            <path d="M12 9v4" /><path d="M12 17h.01" />
                        </svg>
                        <div>
                            <strong>No available stock.</strong>
                            No ready-to-sell stock available for this product variant. Check production status or create new batches.
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <script>
        function updateWeightInputs(allocationId, selectedQty, maxQty) {
            const container = document.getElementById('weights_' + allocationId);
            const rows = container.querySelectorAll('.weight-row');

            rows.forEach((row, index) => {
                if (index < selectedQty) {
                    row.style.display = 'flex';
                    row.querySelector('input').required = true;
                } else {
                    row.style.display = 'none';
                    row.querySelector('input').required = false;
                    row.querySelector('input').value = '';
                }
            });

            updateTotal(allocationId);
        }

        function updateTotal(allocationId) {
            const container = document.getElementById('weights_' + allocationId);
            const inputs = container.querySelectorAll('.weight-input');
            let total = 0;

            inputs.forEach(input => {
                if (input.offsetParent !== null && input.value) {
                    total += parseFloat(input.value) || 0;
                }
            });

            document.getElementById('total_' + allocationId).textContent = total.toFixed(3);
            document.getElementById('actual_weight_' + allocationId).value = total.toFixed(3);
        }
    </script>
</x-app-layout>
