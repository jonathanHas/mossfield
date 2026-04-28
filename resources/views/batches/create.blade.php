<x-app-layout>
    <x-slot name="header">New batch</x-slot>

    <div class="px-6 py-5 max-w-4xl mx-auto">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Create production batch</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Record raw milk used and units produced.</div>
            </div>
            <a href="{{ route('batches.index') }}" class="mf-btn-ghost">← All batches</a>
        </div>

        @if ($errors->any())
            <div class="mf-flash mf-flash-error">
                <div>
                    <strong>Please fix the following errors:</strong>
                    <ul class="mt-1 list-disc list-inside text-[12px]">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="mf-panel">
            <form method="POST" action="{{ route('batches.store') }}" id="batch-form" class="p-5">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                    <div>
                        <x-input-label for="product_id" :value="__('Product')" />
                        <select id="product_id" name="product_id" class="mf-select" required>
                            <option value="">Select product</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}"
                                        data-variants="{{ $product->variants->toJson() }}"
                                        data-maturation-days="{{ $product->maturation_days }}"
                                        {{ old('product_id', request('product_id')) == $product->id ? 'selected' : '' }}>
                                    {{ $product->name }} ({{ ucfirst($product->type) }})
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('product_id')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="production_date" :value="__('Production date')" />
                        <x-text-input id="production_date" type="date" name="production_date" :value="old('production_date', date('Y-m-d'))" required />
                        <x-input-error :messages="$errors->get('production_date')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                    <div>
                        <x-input-label for="raw_milk_litres" :value="__('Raw milk used (litres)')" />
                        <x-text-input id="raw_milk_litres" type="number" step="0.001" name="raw_milk_litres" :value="old('raw_milk_litres')" required />
                        <p class="text-[12px] mt-1" id="raw-milk-note" style="color: var(--muted);">Amount of raw milk used in production.</p>
                        <x-input-error :messages="$errors->get('raw_milk_litres')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="expiry_date" :value="__('Expiry date')" />
                        <x-text-input id="expiry_date" type="date" name="expiry_date" :value="old('expiry_date')" />
                        <p class="text-[12px] mt-1" id="expiry-note" style="color: var(--muted);">Auto-filled for milk products (10 days from production).</p>
                        <x-input-error :messages="$errors->get('expiry_date')" class="mt-1" />
                    </div>
                </div>

                <div id="ready-date-display" class="mb-5" style="display: none;">
                    <div class="mf-flash mf-flash-warn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" />
                        </svg>
                        <div>
                            <strong>Cheese maturation.</strong> This cheese will be ready to sell on
                            <span id="calculated-ready-date" class="font-semibold font-mono"></span>.
                        </div>
                    </div>
                </div>

                <div class="mb-5">
                    <x-input-label for="notes" :value="__('Production notes')" />
                    <textarea id="notes" name="notes" rows="3" class="mf-textarea">{{ old('notes') }}</textarea>
                    <x-input-error :messages="$errors->get('notes')" class="mt-1" />
                </div>

                <div class="mb-5">
                    <h3 class="text-[14px] font-semibold mb-1">Production breakdown by variant</h3>
                    <p class="text-[12.5px] mb-3" id="variant-instructions" style="color: var(--muted);">
                        Please select a product to see its variants.
                    </p>
                    <div id="variants-container">
                        <p class="text-[12.5px] text-center py-4 rounded-md" style="color: var(--muted); border: 1px dashed var(--line);">
                            Please select a product to see its variants.
                        </p>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-4" style="border-top: 1px solid var(--line-2);">
                    <a href="{{ route('batches.index') }}" class="mf-btn-ghost">Cancel</a>
                    <x-primary-button>{{ __('Create batch') }}</x-primary-button>
                </div>
            </form>
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
                    variantsContainer.innerHTML = '<p class="text-[12.5px] text-center py-4 rounded-md" style="color: var(--muted); border: 1px dashed var(--line);">Please select a product to see its variants.</p>';
                    return;
                }

                const selectedOption = productSelect.options[productSelect.selectedIndex];
                const isCheese = selectedOption.text && selectedOption.text.includes('(Cheese)');

                let html = '<div class="space-y-3">';
                variants.forEach((variant, index) => {
                    const isWheelVariant = variant.name.toLowerCase().includes('wheel');
                    const isVacuumPack = variant.name.toLowerCase().includes('vacuum');

                    if (isCheese && isVacuumPack) return;

                    html += `
                        <div class="rounded-lg p-3" style="background: var(--bg); border: 1px solid var(--line);">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div>
                                    <label class="mf-label">${variant.name}</label>
                                    <input type="hidden" name="batch_items[${index}][variant_id]" value="${variant.id}">
                                    <p class="text-[12px]" style="color: var(--muted);">${variant.size} ${variant.unit}</p>
                                    ${isCheese && isWheelVariant ? '<p class="text-[11.5px] mt-1" style="color: var(--accent-ink);">✓ Produced during initial batch</p>' : ''}
                                </div>
                                <div>
                                    <label class="mf-label">Quantity produced</label>
                                    <input type="number" name="batch_items[${index}][quantity_produced]" min="0"
                                        class="mf-input" placeholder="0"
                                        ${isCheese && isWheelVariant ? 'required' : ''}>
                                </div>
                                <div>
                                    <label class="mf-label" id="unit-weight-label-${index}">Unit weight (kg)</label>
                                    <input type="number" step="0.001" name="batch_items[${index}][unit_weight_kg]"
                                        value="${variant.weight_kg || ''}"
                                        class="mf-input" placeholder="0.000"
                                        id="unit-weight-${index}">
                                </div>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';

                if (isCheese) {
                    html += `
                        <div class="mt-3 mf-flash mf-flash-warn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" />
                            </svg>
                            <div>
                                <strong>Cheese production.</strong> Only whole wheels are produced initially.
                                Vacuum packs will be created later when wheels are cut after maturation.
                            </div>
                        </div>
                    `;
                }

                variantsContainer.innerHTML = html;

                if (selectedOption.text && selectedOption.text.includes('(Milk)')) {
                    variants.forEach((variant, index) => {
                        const label = document.getElementById(`unit-weight-label-${index}`);
                        if (label) label.textContent = 'Unit volume (L)';
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
                    expiryDate.setDate(expiryDate.getDate() + 10);

                    expiryDateInput.value = expiryDate.toISOString().split('T')[0];
                }
            }

            function updateFormForProductType(productText) {
                const variantInstructions = document.getElementById('variant-instructions');
                const rawMilkNote = document.getElementById('raw-milk-note');

                if (productText && productText.includes('(Cheese)')) {
                    variantInstructions.innerHTML = '<strong>Required:</strong> Specify how many whole wheels were produced.';
                    rawMilkNote.textContent = 'Raw milk used to produce the cheese wheels (enables yield tracking).';
                } else if (productText && productText.includes('(Milk)')) {
                    variantInstructions.textContent = 'Specify how the raw milk was bottled into different sizes (1L, 2L bottles).';
                    rawMilkNote.textContent = 'Raw milk that was bottled (for milk products this equals the finished volume).';
                } else {
                    variantInstructions.textContent = 'Specify how the finished yoghurt was packaged into different tub sizes.';
                    rawMilkNote.textContent = 'Raw milk used to produce the yoghurt (enables yield tracking).';
                }
            }

            if (productSelect.value) {
                productSelect.dispatchEvent(new Event('change'));
            }
        });
    </script>
</x-app-layout>
