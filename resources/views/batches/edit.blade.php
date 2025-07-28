<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Edit Batch: ') . $batch->batch_code }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <form method="POST" action="{{ route('batches.update', $batch) }}">
                        @csrf
                        @method('PUT')

                        <!-- Read-only Information -->
                        <div class="bg-gray-50 rounded-lg p-4 mb-6">
                            <h3 class="text-sm font-medium text-gray-700 mb-3">Batch Information (Read-only)</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                <div>
                                    <span class="font-medium">Batch Code:</span> {{ $batch->batch_code }}
                                </div>
                                <div>
                                    <span class="font-medium">Product:</span> {{ $batch->product->name }}
                                </div>
                                <div>
                                    <span class="font-medium">Production Date:</span> {{ $batch->production_date->format('d/m/Y') }}
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Expiry Date -->
                            <div>
                                <x-input-label for="expiry_date" :value="__('Expiry Date')" />
                                <x-text-input id="expiry_date" class="block mt-1 w-full" type="date" name="expiry_date" :value="old('expiry_date', $batch->expiry_date?->format('Y-m-d'))" />
                                <x-input-error :messages="$errors->get('expiry_date')" class="mt-2" />
                            </div>

                            <!-- Status -->
                            <div>
                                <x-input-label for="status" :value="__('Status')" />
                                <select id="status" name="status" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                                    <option value="active" {{ old('status', $batch->status) === 'active' ? 'selected' : '' }}>Active</option>
                                    <option value="sold_out" {{ old('status', $batch->status) === 'sold_out' ? 'selected' : '' }}>Sold Out</option>
                                    <option value="expired" {{ old('status', $batch->status) === 'expired' ? 'selected' : '' }}>Expired</option>
                                </select>
                                <x-input-error :messages="$errors->get('status')" class="mt-2" />
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="mt-6">
                            <x-input-label for="notes" :value="__('Production Notes')" />
                            <textarea id="notes" name="notes" rows="4" 
                                    class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('notes', $batch->notes) }}</textarea>
                            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                        </div>

                        <!-- Current Stock Information -->
                        <div class="mt-8">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Current Stock Levels</h3>
                            <div class="bg-gray-50 rounded-lg p-4">
                                @foreach($batch->batchItems as $item)
                                    <div class="flex justify-between items-center py-2 {{ !$loop->last ? 'border-b border-gray-200' : '' }}">
                                        <div>
                                            <span class="font-medium">{{ $item->productVariant->name }}</span>
                                            <span class="text-gray-500 text-sm">({{ $item->productVariant->size }} {{ $item->productVariant->unit }})</span>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-sm text-gray-600">Produced: {{ number_format($item->quantity_produced) }}</div>
                                            <div class="font-medium {{ $item->quantity_remaining > 0 ? 'text-green-600' : 'text-red-600' }}">
                                                Remaining: {{ number_format($item->quantity_remaining) }}
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="flex items-center justify-end mt-8">
                            <a href="{{ route('batches.show', $batch) }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded mr-2">
                                Cancel
                            </a>
                            <x-primary-button>
                                {{ __('Update Batch') }}
                            </x-primary-button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>