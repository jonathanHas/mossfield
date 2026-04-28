<x-app-layout>
    <x-slot name="header">Edit batch {{ $batch->batch_code }}</x-slot>

    <div class="px-6 py-5 max-w-4xl mx-auto">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <div class="text-[12px] font-mono" style="color: var(--muted);">Batch · {{ $batch->product->name }}</div>
                <h1 class="mt-0.5 text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Edit <span class="font-mono">{{ $batch->batch_code }}</span></h1>
            </div>
            <a href="{{ route('batches.show', $batch) }}" class="mf-btn-ghost">← Back to batch</a>
        </div>

        <div class="mf-panel">
            <form method="POST" action="{{ route('batches.update', $batch) }}" class="p-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 px-4 py-3 mb-5 rounded-md text-[13px]" style="background: var(--bg); border: 1px solid var(--line);">
                    <div>
                        <div class="text-[11.5px] uppercase font-medium" style="color: var(--muted); letter-spacing: 0.4px;">Batch code</div>
                        <div class="mt-0.5 font-mono font-semibold">{{ $batch->batch_code }}</div>
                    </div>
                    <div>
                        <div class="text-[11.5px] uppercase font-medium" style="color: var(--muted); letter-spacing: 0.4px;">Product</div>
                        <div class="mt-0.5">{{ $batch->product->name }}</div>
                    </div>
                    <div>
                        <div class="text-[11.5px] uppercase font-medium" style="color: var(--muted); letter-spacing: 0.4px;">Production date</div>
                        <div class="mt-0.5 font-mono">{{ $batch->production_date->format('d/m/Y') }}</div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                    <div>
                        <x-input-label for="expiry_date" :value="__('Expiry date')" />
                        <x-text-input id="expiry_date" type="date" name="expiry_date" :value="old('expiry_date', $batch->expiry_date?->format('Y-m-d'))" />
                        <x-input-error :messages="$errors->get('expiry_date')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="status" :value="__('Status')" />
                        <select id="status" name="status" class="mf-select" required>
                            <option value="active" {{ old('status', $batch->status) === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="sold_out" {{ old('status', $batch->status) === 'sold_out' ? 'selected' : '' }}>Sold out</option>
                            <option value="expired" {{ old('status', $batch->status) === 'expired' ? 'selected' : '' }}>Expired</option>
                        </select>
                        <x-input-error :messages="$errors->get('status')" class="mt-1" />
                    </div>
                </div>

                <div class="mb-5">
                    <x-input-label for="notes" :value="__('Production notes')" />
                    <textarea id="notes" name="notes" rows="4" class="mf-textarea">{{ old('notes', $batch->notes) }}</textarea>
                    <x-input-error :messages="$errors->get('notes')" class="mt-1" />
                </div>

                <div class="mb-5">
                    <h3 class="text-[14px] font-semibold mb-3">Current stock levels</h3>
                    <div class="rounded-md p-3" style="background: var(--bg); border: 1px solid var(--line);">
                        @foreach($batch->batchItems as $item)
                            <div class="flex justify-between items-center py-2 {{ !$loop->last ? '' : '' }}" @style(['border-bottom: 1px solid var(--line-2)' => !$loop->last])>
                                <div>
                                    <span class="font-medium text-[13px]">{{ $item->productVariant->name }}</span>
                                    <span class="text-[12px]" style="color: var(--muted);">({{ $item->productVariant->size }} {{ $item->productVariant->unit }})</span>
                                </div>
                                <div class="text-right">
                                    <div class="text-[12px]" style="color: var(--muted);">Produced: <span class="font-mono">{{ number_format($item->quantity_produced) }}</span></div>
                                    <div class="text-[13px] font-mono font-medium" style="color: {{ $item->quantity_remaining > 0 ? 'var(--accent-ink)' : 'var(--danger)' }};">
                                        Remaining: {{ number_format($item->quantity_remaining) }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-4" style="border-top: 1px solid var(--line-2);">
                    <a href="{{ route('batches.show', $batch) }}" class="mf-btn-ghost">Cancel</a>
                    <x-primary-button>{{ __('Update batch') }}</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
