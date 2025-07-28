<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Cut Cheese Wheel') }}
            </h2>
            <a href="{{ route('cheese-cutting.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                Back to Cutting
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <!-- Display validation errors -->
                    @if ($errors->any())
                        <div class="mb-6 bg-red-50 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                            <strong class="font-bold">Please fix the following errors:</strong>
                            <ul class="mt-2 list-disc list-inside">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <!-- Batch Information -->
                    <div class="mb-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-yellow-800 mb-2">Wheel to Cut</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <p><strong>Product:</strong> {{ $batchItem->batch->product->name }}</p>
                                <p><strong>Batch Code:</strong> {{ $batchItem->batch->batch_code }}</p>
                                <p><strong>Production Date:</strong> {{ $batchItem->batch->production_date->format('d/m/Y') }}</p>
                            </div>
                            <div>
                                <p><strong>Variant:</strong> {{ $batchItem->productVariant->name }}</p>
                                <p><strong>Wheels Available:</strong> {{ $batchItem->quantity_remaining }}</p>
                                <p><strong>Weight per Wheel:</strong> {{ number_format($batchItem->unit_weight_kg, 2) }}kg</p>
                            </div>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('cheese-cutting.store', $batchItem) }}">
                        @csrf

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Cut Date -->
                            <div>
                                <x-input-label for="cut_date" :value="__('Cut Date')" />
                                <x-text-input id="cut_date" class="block mt-1 w-full" type="date" name="cut_date" :value="old('cut_date', date('Y-m-d'))" required />
                                <x-input-error :messages="$errors->get('cut_date')" class="mt-2" />
                            </div>

                            <!-- Vacuum Pack Variant -->
                            <div>
                                <x-input-label for="vacuum_pack_variant_id" :value="__('Vacuum Pack Type')" />
                                <select id="vacuum_pack_variant_id" name="vacuum_pack_variant_id" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                                    <option value="">Select vacuum pack type</option>
                                    @foreach($vacuumPackVariants as $variant)
                                        <option value="{{ $variant->id }}" {{ old('vacuum_pack_variant_id') == $variant->id ? 'selected' : '' }}>
                                            {{ $variant->name }} ({{ number_format($variant->weight_kg, 3) }}kg each)
                                        </option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('vacuum_pack_variant_id')" class="mt-2" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                            <!-- Number of Vacuum Packs -->
                            <div>
                                <x-input-label for="vacuum_packs_created" :value="__('Vacuum Packs Created')" />
                                <x-text-input id="vacuum_packs_created" class="block mt-1 w-full" type="number" min="1" name="vacuum_packs_created" :value="old('vacuum_packs_created')" required />
                                <p class="text-sm text-gray-600 mt-1">Number of vacuum packs created from this wheel</p>
                                <x-input-error :messages="$errors->get('vacuum_packs_created')" class="mt-2" />
                            </div>

                            <!-- Total Weight -->
                            <div>
                                <x-input-label for="total_weight_kg" :value="__('Total Weight (kg)')" />
                                <x-text-input id="total_weight_kg" class="block mt-1 w-full" type="number" step="0.001" name="total_weight_kg" :value="old('total_weight_kg')" required />
                                <p class="text-sm text-gray-600 mt-1">Auto-calculated from pack count × unit weight (editable)</p>
                                <x-input-error :messages="$errors->get('total_weight_kg')" class="mt-2" />
                            </div>
                        </div>

                        <!-- Cutting Notes -->
                        <div class="mt-6">
                            <x-input-label for="notes" :value="__('Cutting Notes (Optional)')" />
                            <textarea id="notes" name="notes" rows="3" 
                                    class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                    placeholder="Any notes about the cutting process, quality, or special observations...">{{ old('notes') }}</textarea>
                            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                        </div>

                        <!-- Weight Calculator Helper -->
                        <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <h4 class="text-sm font-medium text-blue-800 mb-2">Weight Calculator</h4>
                            <div class="text-sm text-blue-700">
                                <p>Average pack weight will be: <span id="average-weight" class="font-semibold">-</span> kg per pack</p>
                                <p class="text-xs mt-1">This is calculated automatically based on total weight ÷ number of packs</p>
                            </div>
                        </div>

                        <div class="flex items-center justify-end mt-8">
                            <a href="{{ route('cheese-cutting.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded mr-2">
                                Cancel
                            </a>
                            <x-primary-button>
                                {{ __('Cut Wheel') }}
                            </x-primary-button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const vacuumPackVariantSelect = document.getElementById('vacuum_pack_variant_id');
            const vacuumPacksInput = document.getElementById('vacuum_packs_created');
            const totalWeightInput = document.getElementById('total_weight_kg');
            const averageWeightSpan = document.getElementById('average-weight');

            function getSelectedVariantWeight() {
                const selectedOption = vacuumPackVariantSelect.options[vacuumPackVariantSelect.selectedIndex];
                if (selectedOption.value) {
                    // Extract weight from option text like "Vacuum Pack (0.250kg each)"
                    const match = selectedOption.textContent.match(/\(([0-9.]+)kg each\)/);
                    return match ? parseFloat(match[1]) : 0;
                }
                return 0;
            }

            function updateTotalWeight() {
                const packs = parseInt(vacuumPacksInput.value) || 0;
                const unitWeight = getSelectedVariantWeight();
                
                if (packs > 0 && unitWeight > 0) {
                    const totalWeight = (packs * unitWeight).toFixed(3);
                    totalWeightInput.value = totalWeight;
                    totalWeightInput.style.backgroundColor = '#f0f9ff'; // Light blue to indicate auto-filled
                    updateAverageWeight();
                } else {
                    totalWeightInput.style.backgroundColor = '';
                }
            }

            function updateAverageWeight() {
                const packs = parseInt(vacuumPacksInput.value) || 0;
                const totalWeight = parseFloat(totalWeightInput.value) || 0;
                
                if (packs > 0 && totalWeight > 0) {
                    const average = (totalWeight / packs).toFixed(3);
                    averageWeightSpan.textContent = average;
                } else {
                    averageWeightSpan.textContent = '-';
                }
            }

            // Event listeners
            vacuumPackVariantSelect.addEventListener('change', updateTotalWeight);
            vacuumPacksInput.addEventListener('input', updateTotalWeight);
            totalWeightInput.addEventListener('input', function() {
                // Remove auto-fill styling when manually edited
                this.style.backgroundColor = '';
                updateAverageWeight();
            });

            // Initialize if values are already selected
            if (vacuumPackVariantSelect.value && vacuumPacksInput.value) {
                updateTotalWeight();
            }
        });
    </script>
</x-app-layout>