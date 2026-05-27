{{--
    Inline stock-allocation block for the order detail page (Picking-page v2).
    Renders the list of order lines only — the "Items" section head + Auto-allocate
    button live in orders/show.blade.php so they stay shared with the read-only branch.

    Expects $order (with orderItems.orderAllocations.batchItem.batch loaded) and
    $availableBatchItems (array keyed by order item id; empty for users who cannot
    update the order). Fully-fulfilled lines collapse to a one-line summary; the
    fulfilment panel (the only "card" on the page) is reserved for lines still
    needing action and re-opens via the per-line "Adjust" toggle. Every interactive
    form is gated by @can('update', $order) so view-only roles see data read-only.
--}}
@foreach($order->orderItems as $orderItem)
    @php
        $fulfilled = $orderItem->isFullyFulfilled();
        $firstAlloc = $orderItem->orderAllocations->first();
        $fulfillPct = $orderItem->quantity_ordered > 0
            ? min(100, ($orderItem->quantity_fulfilled / $orderItem->quantity_ordered) * 100)
            : 0;
        $isOnlyLine = $order->orderItems->count() <= 1;
    @endphp

    <x-order.item-row :item="$orderItem" :order="$order" x-data="{ open: {{ $fulfilled ? 'false' : 'true' }} }">
        <x-slot:summary>
            <div class="flex items-center gap-3.5 mt-2.5 text-[12.5px] flex-wrap" style="color: var(--muted);">
                @if($fulfilled)
                    <span class="inline-flex items-center gap-1.5 font-medium" style="color: var(--accent-ink);">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l3 3 5-6"/></svg>
                        {{ $orderItem->quantity_fulfilled }} of {{ $orderItem->quantity_ordered }} fulfilled
                    </span>
                    @if($firstAlloc)
                        <span style="color: var(--line);">·</span>
                        <span>from batch <span class="font-mono" style="color: var(--ink-2);">{{ $firstAlloc->batchItem->batch->batch_code }}</span> ({{ $firstAlloc->batchItem->batch->production_date->format('d M') }})</span>
                    @endif
                    @if($orderItem->isVariableWeight() && $orderItem->weight_fulfilled_kg > 0)
                        <span style="color: var(--line);">·</span>
                        <span class="font-mono" style="color: var(--ink-2);">{{ number_format($orderItem->weight_fulfilled_kg, 3) }} kg</span>
                    @endif
                    @can('update', $order)
                        <span style="color: var(--line);">·</span>
                        <button type="button" class="mf-link" style="color: var(--muted);" @click="open = ! open">Adjust</button>
                    @endcan
                @else
                    <span class="inline-flex items-center gap-1.5 font-medium" style="color: var(--warn-ink);">
                        <span class="inline-block rounded-full" style="width: 7px; height: 7px; background: var(--warn);"></span>
                        Needs fulfilment
                    </span>
                    <span style="color: var(--line);">·</span>
                    <span class="font-mono">{{ $orderItem->quantity_allocated }} allocated · {{ $orderItem->quantity_fulfilled }} fulfilled</span>
                @endif
            </div>
        </x-slot:summary>

        {{-- Expanded fulfilment panel (collapsed by default for fully-fulfilled lines). --}}
        <div class="mt-3.5" x-show="open" @if($fulfilled) style="display: none;" @endif>
            <div class="rounded-lg p-4" style="background: var(--panel); border: 1px solid var(--line);">
                <div class="flex items-center justify-between mb-2.5">
                    <span class="mf-eyebrow">Current allocations</span>
                    <span class="text-[12px] font-mono" style="color: var(--ink-2);">{{ $orderItem->quantity_fulfilled }} of {{ $orderItem->quantity_ordered }} fulfilled</span>
                </div>
                <div class="mf-bar mb-3.5"><i style="width: {{ $fulfillPct }}%"></i></div>

                @if($orderItem->orderAllocations->count() > 0)
                    <div class="overflow-x-auto rounded-md mb-3" style="border: 1px solid var(--line);">
                        <table class="w-full border-collapse text-[12.5px]">
                            <thead>
                                <tr>
                                    <th class="mf-th">Batch</th>
                                    <th class="mf-th">Production</th>
                                    <th class="mf-th text-right">Allocated</th>
                                    <th class="mf-th text-right">Fulfilled</th>
                                    @if($orderItem->isVariableWeight())
                                        <th class="mf-th text-right">Weight</th>
                                    @endif
                                    @can('update', $order)
                                        <th class="mf-th">Action</th>
                                    @endcan
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($orderItem->orderAllocations as $allocation)
                                    <tr style="border-top: 1px solid var(--line-2);">
                                        <td class="mf-td font-mono" style="color: var(--ink-2);">{{ $allocation->batchItem->batch->batch_code }}</td>
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
                                        @can('update', $order)
                                            <td class="mf-td">
                                                <div class="space-y-2">
                                                    @if($allocation->quantity_remaining > 0)
                                                        @if($orderItem->isVariableWeight() && ! $orderItem->isBulkWeighed())
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
                                                                        <button type="submit" class="mf-link" style="color: var(--accent-ink);">Fulfil</button>
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
                                                        @elseif($orderItem->isVariableWeight() && $orderItem->isBulkWeighed())
                                                            {{-- Bulk-weighed (e.g. vacuum packs): one total weight for the line. --}}
                                                            <form method="POST" action="{{ route('order-allocations.fulfill', $allocation) }}">
                                                                @csrf
                                                                <div class="space-y-2">
                                                                    <div class="flex items-center gap-2">
                                                                        <label class="text-[11.5px] w-20" style="color: var(--muted);">Qty:</label>
                                                                        <input type="number" name="quantity"
                                                                            min="1" max="{{ $allocation->quantity_remaining }}"
                                                                            value="{{ $allocation->quantity_remaining }}"
                                                                            class="mf-input w-16 px-2 py-0.5 text-[12px]">
                                                                    </div>
                                                                    <div class="flex items-center gap-1">
                                                                        <label class="text-[11.5px] w-20" style="color: var(--muted);">Total weight:</label>
                                                                        <input type="number" name="actual_weight_kg"
                                                                            step="0.001" min="0.001"
                                                                            placeholder="{{ $allocation->expected_weight ? number_format($allocation->expected_weight, 3) : '' }}"
                                                                            class="mf-input w-24 px-2 py-0.5 text-[12px]" required>
                                                                        <span class="text-[11.5px]" style="color: var(--muted);">kg</span>
                                                                    </div>
                                                                    <button type="submit" class="mf-link" style="color: var(--accent-ink);">Fulfil</button>
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
                                                                    <button type="submit" class="mf-link" style="color: var(--accent-ink);">Fulfil</button>
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
                                        @endcan
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @can('update', $order)
                    @if($orderItem->quantity_allocated < $orderItem->quantity_ordered && isset($availableBatchItems[$orderItem->id]) && $availableBatchItems[$orderItem->id]->count() > 0)
                        <div>
                            <span class="mf-eyebrow">Available stock</span>
                            <div class="rounded-md p-3 mt-2" style="background: var(--info-soft); border: 1px solid oklch(0.92 0.04 235);">
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
                        <div class="mf-flash mf-flash-warn" style="margin-bottom: 0;">
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

                    {{-- Line-level edit / remove (picking statuses are always editable). --}}
                    <div class="flex items-center gap-3 mt-3 pt-3 flex-wrap" style="border-top: 1px solid var(--line-2);">
                        <form method="POST" action="{{ route('orders.items.update', [$order, $orderItem]) }}" class="flex items-center gap-1.5">
                            @csrf @method('PATCH')
                            <label class="text-[11.5px]" style="color: var(--muted);">Qty:</label>
                            <input type="number" name="quantity" min="1" value="{{ $orderItem->quantity_ordered }}"
                                class="mf-input w-16 px-2 py-0.5 text-[12px]">
                            <button type="submit" class="mf-link" style="color: var(--accent-ink);">Update</button>
                        </form>
                        <span style="color: var(--line);">·</span>
                        <form method="POST" action="{{ route('orders.items.destroy', [$order, $orderItem]) }}" class="inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="mf-link" style="color: var(--danger);"
                                onclick="return confirm('{{ $isOnlyLine ? 'This is the only item — removing it will cancel the order and return any picked stock to its batch. Continue?' : 'Remove this line? Any picked stock will be returned to its batch.' }}')">{{ $isOnlyLine ? 'Remove (cancels order)' : 'Remove line' }}</button>
                        </form>
                    </div>
                @endcan
            </div>
        </div>
    </x-order.item-row>
@endforeach

@can('update', $order)
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
@endcan
