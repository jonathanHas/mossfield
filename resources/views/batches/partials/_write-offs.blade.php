{{--
    Write-offs on this batch (stock that was lost — spoiled, dropped, sampled,
    expired …). Office/admin record and reverse here; factory sees the
    history. Expects $batch (batchItems.writeOffs loaded), $writeOffReasons
    (active, empty for read-only users) and $writeOffs (newest first).
--}}
@php
    $woItems = $batch->batchItems->filter(fn ($i) => (int) $i->quantity_produced > 0)->values();
    $weighedIds = $woItems->filter(fn ($i) => $i->productVariant?->is_variable_weight)->pluck('id')->map(fn ($id) => (string) $id)->values();
    $firstItemId = (string) old('batch_item_id', $woItems->first()?->id);
@endphp

<div class="mf-panel mb-4 mf-landing" id="write-offs">
    <div class="mf-panel-header">
        <div class="flex-1">
            <div class="text-[13px] font-semibold">Write-offs</div>
            <div class="text-[12px] mt-0.5" style="color: var(--muted);">Stock that was lost. Written-off units leave sellable stock but still count in the batch's finished weight.</div>
        </div>
    </div>

    @can('create', App\Models\StockWriteOff::class)
        @if($woItems->isNotEmpty())
            <form method="POST" action="{{ route('batches.write-offs.store', $batch) }}" class="p-4 grid grid-cols-1 md:grid-cols-6 gap-3 items-end"
                  style="border-bottom: 1px solid var(--line-2);"
                  x-data="{ item: @js($firstItemId), weighed: @js($weighedIds), qty: @js((string) old('quantity', '1')) }">
                @csrf
                @if($woItems->count() > 1)
                    <div class="md:col-span-2">
                        <label for="wo-item" class="mf-label">Item</label>
                        <select id="wo-item" name="batch_item_id" class="mf-select" x-model="item" required>
                            @foreach($woItems as $woItem)
                                <option value="{{ $woItem->id }}" @selected($firstItemId === (string) $woItem->id)>
                                    {{ $woItem->productVariant->name }} — {{ $woItem->quantity_remaining }} on hand
                                </option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <input type="hidden" name="batch_item_id" value="{{ $woItems->first()->id }}">
                    <div class="md:col-span-2 text-[13px]">
                        <span class="mf-label">Item</span>
                        {{ $woItems->first()->productVariant->name }} — {{ $woItems->first()->quantity_remaining }} on hand
                    </div>
                @endif
                <div>
                    <label for="wo-qty" class="mf-label">Quantity</label>
                    <input type="number" id="wo-qty" name="quantity" min="1" required class="mf-input" x-model="qty">
                </div>
                <div x-show="weighed.includes(item)" x-cloak class="md:col-span-2">
                    <label for="wo-weight" class="mf-label">Measured weight of these <span x-text="qty || 'N'"></span> units (kg)</label>
                    {{-- A typed comma becomes a decimal point (a number input would drop it: 4,900 → 4900; E2E F-29). --}}
                    <input type="number" id="wo-weight" name="weight_kg" step="0.001" min="0.001" inputmode="decimal" class="mf-input" value="{{ old('weight_kg') }}" placeholder="optional"
                           x-bind:disabled="! weighed.includes(item)"
                           x-on:keydown="if ($event.key === ',') { $event.preventDefault(); document.execCommand('insertText', false, '.'); }">
                </div>
                <div>
                    <label for="wo-date" class="mf-label">Date</label>
                    <input type="date" id="wo-date" name="written_off_on" required class="mf-input"
                           value="{{ old('written_off_on', today()->toDateString()) }}" max="{{ today()->toDateString() }}">
                </div>
                <div class="md:col-span-2">
                    <label for="wo-reason" class="mf-label">Reason</label>
                    <select id="wo-reason" name="write_off_reason_id" class="mf-select" required>
                        <option value="">Choose a reason…</option>
                        @foreach($writeOffReasons as $reason)
                            <option value="{{ $reason->id }}" @selected((string) old('write_off_reason_id') === (string) $reason->id)>{{ $reason->label }}</option>
                        @endforeach
                    </select>
                    <a href="{{ route('write-off-reasons.index') }}" class="mf-link text-[11.5px]">Manage reasons</a>
                </div>
                <div class="md:col-span-3">
                    <label for="wo-note" class="mf-label">Note</label>
                    <input type="text" id="wo-note" name="note" maxlength="255" class="mf-input" value="{{ old('note') }}" placeholder="optional">
                </div>
                <div>
                    <button type="submit" class="mf-btn-primary w-full justify-center">Write off</button>
                </div>
                @error('write_off') <p class="mf-error md:col-span-6">{{ $message }}</p> @enderror
            </form>
        @endif
    @endcan

    @if($writeOffs->isEmpty())
        <div class="px-4 py-6 text-[13px]" style="color: var(--muted);">Nothing written off.</div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th class="mf-th">Date</th>
                        <th class="mf-th">Item</th>
                        <th class="mf-th text-right">Qty</th>
                        <th class="mf-th text-right">kg</th>
                        <th class="mf-th">Reason</th>
                        <th class="mf-th">Note</th>
                        <th class="mf-th">By</th>
                        <th class="mf-th"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($writeOffs as $wo)
                        @php $reversed = $wo->isReversed(); @endphp
                        <tr style="border-top: 1px solid var(--line-2); {{ $reversed ? 'color: var(--muted);' : '' }}" data-write-off="{{ $wo->id }}">
                            <td class="mf-td font-mono {{ $reversed ? 'line-through' : '' }}">{{ $wo->written_off_on->format('d/m/Y') }}</td>
                            <td class="mf-td {{ $reversed ? 'line-through' : '' }}">{{ $wo->batchItem->productVariant->name }}</td>
                            <td class="mf-td font-mono text-right {{ $reversed ? 'line-through' : '' }}">{{ $wo->quantity }}</td>
                            <td class="mf-td font-mono text-right">{{ $wo->weight_kg !== null ? number_format((float) $wo->weight_kg, 3) : '—' }}</td>
                            <td class="mf-td">{{ $wo->reason?->label }}</td>
                            <td class="mf-td">{{ $wo->note }}</td>
                            <td class="mf-td">{{ $wo->writtenOffBy?->name ?? '—' }}</td>
                            <td class="mf-td text-right whitespace-nowrap">
                                @if($reversed)
                                    <span class="text-[12px]">reversed {{ $wo->reversed_at->format('d/m/Y') }} by {{ $wo->reversedBy?->name ?? '—' }}</span>
                                @else
                                    @can('update', $wo)
                                        <form method="POST" action="{{ route('write-offs.reverse', $wo) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="mf-link text-[12px]"
                                                    data-confirm="Reverse this write-off? {{ $wo->quantity }} {{ Str::plural('unit', $wo->quantity) }} {{ $wo->quantity === 1 ? 'goes' : 'go' }} back into free stock."
                                                    data-confirm-label="Reverse">Reverse</button>
                                        </form>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
