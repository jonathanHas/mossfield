<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Create Production Batch') }}
        </h2>
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

                    <form method="POST" action="{{ route('batches.store') }}" id="batch-form">
                        @csrf

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Product Selection -->
                            <div>
                                <x-input-label for="product_id" :value="__('Product')" />
                                <select id="product_id" name="product_id" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                                    <option value="">Select Product</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}" 
                                                data-variants="{{ $product->variants->toJson() }}"
                                                data-maturation-days="{{ $product->maturation_days }}"
                                                {{ old('product_id') == $product->id ? 'selected' : '' }}>
                                            {{ $product->name }} ({{ ucfirst($product->type) }})
                                        </option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('product_id')" class="mt-2" />
                            </div>

                            <!-- Production Date -->
                            <div>
                                <x-input-label for="production_date" :value="__('Production Date')" />
                                <x-text-input id="production_date" class="block mt-1 w-full" type="date" name="production_date" :value="old('production_date', date('Y-m-d'))" required />
                                <x-input-error :messages="$errors->get('production_date')" class="mt-2" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                            <!-- Raw Milk Input (all products) -->
                            <div>
                                <x-input-label for="raw_milk_litres" :value="__('Raw Milk Used (Litres)')" />
                                <x-text-input id="raw_milk_litres" class="block mt-1 w-full" type="number" step="0.001" name="raw_milk_litres" :value="old('raw_milk_litres')" required />
                                <p class="text-sm text-gray-600 mt-1" id="raw-milk-note">Amount of raw milk used in production</p>
                                <x-input-error :messages="$errors->get('raw_milk_litres')" class="mt-2" />
                            </div>

                            <!-- Cheese Wheels Production (visible for cheese only) -->
                            <div id="cheese-wheels-section" style="display: none;">
                                <x-input-label for="wheels_produced" :value="__('Number of Wheels Produced')" />
                                <x-text-input id="wheels_produced" class="block mt-1 w-full" type="number" name="wheels_produced" :value="old('wheels_produced')" />
                                <p class="text-sm text-gray-600 mt-1">Finished product count from the raw milk above</p>
                                <x-input-error :messages="$errors->get('wheels_produced')" class="mt-2" />
                            </div>

                            <!-- Expiry Date (auto-filled for milk) -->
                            <div>
                                <x-input-label for="expiry_date" :value="__('Expiry Date')" />
                                <x-text-input id="expiry_date" class="block mt-1 w-full" type="date" name="expiry_date" :value="old('expiry_date')" />
                                <p class="text-sm text-gray-600 mt-1" id="expiry-note">Auto-filled for milk products (10 days from production)</p>
                                <x-input-error :messages="$errors->get('expiry_date')" class="mt-2" />
                            </div>
                        </div>

                        <!-- Ready Date Display (for cheese) -->
                        <div id="ready-date-display" class="mt-6" style="display: none;">
                            <div class="bg-yellow-50 border border-yellow-200 rounded-md p-4">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <h3 class="text-sm font-medium text-yellow-800">Cheese Maturation</h3>
                                        <div class="mt-2 text-sm text-yellow-700">
                                            <p>This cheese will be ready to sell on: <span id="calculated-ready-date" class="font-semibold"></span></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="mt-6">
                            <x-input-label for="notes" :value="__('Production Notes')" />
                            <textarea id="notes" name="notes" rows="3" 
                                    class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('notes') }}</textarea>
                            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                        </div>

                        <!-- Product Variants Section -->
                        <div class="mt-8">
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">Production Breakdown by Variant</h3>
                            <p class="text-sm text-gray-600 mb-4" id="variant-instructions">
                                Please select a product to see its variants
                            </p>
                            <div id="variants-container">
                                <p class="text-gray-500 text-center py-4">Please select a product to see its variants</p>
                            </div>
                        </div>

                        <div class="flex items-center justify-end mt-8">
                            <a href="{{ route('batches.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded mr-2">
                                Cancel
                            </a>
                            <x-primary-button>
                                {{ __('Create Batch') }}
                            </x-primary-button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const productSelect = document.getElementById('product_id');
            const productionDateInput = document.getElementById('production_date');
            const variantsContainer = document.getElementById('variants-container');
            const readyDateDisplay = document.getElementById('ready-date-display');
            const calculatedReadyDate = document.getElementById('calculated-ready-date');

            productSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const variants = selectedOption.dataset.variants ? JSON.parse(selectedOption.dataset.variants) : [];
                const maturationDays = selectedOption.dataset.maturationDays;
                const productText = selectedOption.text;

                updateVariantsSection(variants);
                updateReadyDate(maturationDays);
                updateFormForProductType(productText);
            });

            productionDateInput.addEventListener('change', function() {
                const selectedOption = productSelect.options[productSelect.selectedIndex];
                const maturationDays = selectedOption.dataset.maturationDays;
                updateReadyDate(maturationDays);
                updateExpiryDate();
            });

            productSelect.addEventListener('change', function() {
                updateExpiryDate();
            });

            function updateVariantsSection(variants) {
                if (variants.length === 0) {
                    variantsContainer.innerHTML = '<p class="text-gray-500 text-center py-4">Please select a product to see its variants</p>';
                    return;
                }

                // Check if this is a cheese product by looking at the selected product text
                const selectedOption = productSelect.options[productSelect.selectedIndex];
                const isCheese = selectedOption.text && selectedOption.text.includes('(Cheese)');

                let html = '<div class="space-y-4">';
                variants.forEach((variant, index) => {
                    // For cheese, only show whole wheel variants during production
                    const isWheelVariant = variant.name.toLowerCase().includes('wheel');
                    const isVacuumPack = variant.name.toLowerCase().includes('vacuum');
                    
                    if (isCheese && isVacuumPack) {
                        // Skip vacuum pack variants for cheese production - they're created later via cutting
                        return;
                    }

                    html += `
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">${variant.name}</label>
                                    <input type="hidden" name="batch_items[${index}][variant_id]" value="${variant.id}">
                                    <p class="text-sm text-gray-600">${variant.size} ${variant.unit}</p>
                                    ${isCheese && isWheelVariant ? '<p class="text-xs text-green-600 mt-1">✓ Produced during initial batch</p>' : ''}
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Quantity Produced</label>
                                    <input 
                                        type="number" 
                                        name="batch_items[${index}][quantity_produced]" 
                                        min="0" 
                                        class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                                        placeholder="0"
                                        ${isCheese && isWheelVariant ? 'required' : ''}
                                    >
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1" id="unit-weight-label-${index}">Unit Weight (kg)</label>
                                    <input 
                                        type="number" 
                                        step="0.001" 
                                        name="batch_items[${index}][unit_weight_kg]" 
                                        value="${variant.weight_kg || ''}"
                                        class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                                        placeholder="0.000"
                                        id="unit-weight-${index}"
                                    >
                                </div>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                
                // Add note for cheese about vacuum packs
                if (isCheese) {
                    html += `
                        <div class="mt-4 bg-blue-50 border border-blue-200 rounded-md p-4">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-blue-800">Cheese Production Note</h3>
                                    <div class="mt-2 text-sm text-blue-700">
                                        <p>Only whole wheels are produced initially. Vacuum packs will be created later when wheels are cut after maturation.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                variantsContainer.innerHTML = html;
                
                // Update unit weight labels for milk products
                if (selectedOption.text && selectedOption.text.includes('(Milk)')) {
                    variants.forEach((variant, index) => {
                        const label = document.getElementById(`unit-weight-label-${index}`);
                        if (label) {
                            label.textContent = 'Unit Volume (L)';
                        }
                    });
                }
            }

            function updateReadyDate(maturationDays) {
                if (maturationDays && productionDateInput.value) {
                    const productionDate = new Date(productionDateInput.value);
                    const readyDate = new Date(productionDate);
                    readyDate.setDate(readyDate.getDate() + parseInt(maturationDays));
                    
                    calculatedReadyDate.textContent = readyDate.toLocaleDateString('en-GB');
                    readyDateDisplay.style.display = 'block';
                } else {
                    readyDateDisplay.style.display = 'none';
                }
            }

            function updateExpiryDate() {
                const selectedOption = productSelect.options[productSelect.selectedIndex];
                const expiryDateInput = document.getElementById('expiry_date');
                
                if (selectedOption.text && selectedOption.text.includes('(Milk)') && productionDateInput.value) {
                    const productionDate = new Date(productionDateInput.value);
                    const expiryDate = new Date(productionDate);
                    expiryDate.setDate(expiryDate.getDate() + 10); // 10 days from production
                    
                    expiryDateInput.value = expiryDate.toISOString().split('T')[0];
                    expiryDateInput.style.backgroundColor = '#f0f9ff'; // Light blue to indicate auto-filled
                } else {
                    expiryDateInput.style.backgroundColor = '';
                }
            }

            function updateFormForProductType(productText) {
                const cheeseWheelsSection = document.getElementById('cheese-wheels-section');
                const wheelsInput = document.getElementById('wheels_produced');
                const variantInstructions = document.getElementById('variant-instructions');
                const rawMilkNote = document.getElementById('raw-milk-note');
                
                if (productText && productText.includes('(Cheese)')) {
                    // Show cheese wheels section
                    cheeseWheelsSection.style.display = 'block';
                    
                    // Update instructions for cheese
                    variantInstructions.innerHTML = '<strong>Required:</strong> Specify how many whole wheels were produced. This should match the wheel count above.';
                    rawMilkNote.textContent = 'Raw milk used to produce the cheese wheels (enables yield tracking)';
                    
                    // Make wheels required for cheese
                    wheelsInput.required = true;
                } else if (productText && productText.includes('(Milk)')) {
                    // Hide cheese wheels section
                    cheeseWheelsSection.style.display = 'none';
                    
                    // Update instructions for milk
                    variantInstructions.textContent = 'Specify how the raw milk was bottled into different sizes (1L, 2L bottles).';
                    rawMilkNote.textContent = 'Raw milk that was bottled (for milk products this equals the finished volume)';
                    
                    // Reset values
                    wheelsInput.required = false;
                    wheelsInput.value = '';
                } else {
                    // Hide cheese wheels section (for yoghurt)
                    cheeseWheelsSection.style.display = 'none';
                    
                    // Update instructions for yoghurt
                    variantInstructions.textContent = 'Specify how the finished yoghurt was packaged into different tub sizes.';
                    rawMilkNote.textContent = 'Raw milk used to produce the yoghurt (enables yield tracking)';
                    
                    // Reset values
                    wheelsInput.required = false;
                    wheelsInput.value = '';
                }
            }

            // Initialize if product is already selected (from old input)
            if (productSelect.value) {
                productSelect.dispatchEvent(new Event('change'));
            }
        });
    </script>
</x-app-layout>