<x-app-layout>
    <x-slot name="header">{{ $product->name }} · New variant</x-slot>

    @php
        $listFilters = $listFilters ?? [];
        $productList = $productList ?? collect();
        $listTotal = $listTotal ?? $productList->count();
        $listLimit = $listLimit ?? 50;
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-[320px_1fr]">
        <aside class="hidden lg:flex lg:flex-col" style="border-right: 1px solid var(--line-2); max-height: calc(100vh - var(--header-height, 64px)); overflow-y: auto;">
            @include('products._sibling_list', ['mode' => 'show', 'showActiveVariants' => true, 'activeVariant' => null])
        </aside>

        <main class="px-6 py-5 max-w-3xl">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <div class="text-[12px] font-mono" style="color: var(--muted);">{{ $product->name }}</div>
                <h1 class="mt-0.5 text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">
                    Add variant
                    @isset($source)
                        <span class="text-[14px] font-normal" style="color: var(--muted);">· duplicating "{{ $source->name }}"</span>
                    @endisset
                </h1>
            </div>
            <a href="{{ route('products.show', $product) }}" class="mf-btn-ghost lg:hidden">← Back to product</a>
        </div>

        <div class="mf-panel">
            <form method="POST" action="{{ route('products.variants.store', $product) }}" enctype="multipart/form-data" class="p-5">
                @csrf

                <div class="mb-4">
                    <label for="name" class="mf-label">Variant name <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="name" id="name" value="{{ old('name', $source?->name) }}" required class="mf-input"
                           placeholder="e.g., 1L Bottle, Whole Wheel, Vacuum Pack">
                    @error('name') <p class="mf-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="size" class="mf-label">Size <span style="color: var(--danger);">*</span></label>
                        <input type="text" name="size" id="size" value="{{ old('size', $source?->size) }}" required class="mf-input"
                               placeholder="e.g., 1L, 250g, wheel">
                        @error('size') <p class="mf-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="unit" class="mf-label">Unit <span style="color: var(--danger);">*</span></label>
                        <input type="text" name="unit" id="unit" value="{{ old('unit', $source?->unit) }}" required class="mf-input"
                               placeholder="e.g., bottle, tub, wheel">
                        @error('unit') <p class="mf-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mb-4">
                    <label for="image" class="mf-label">Variant image</label>
                    <input id="image" name="image" type="file" accept="image/*"
                           onchange="previewVariantImage(event)" class="block w-full text-[13px]" />
                    <p class="text-[12px] mt-1" style="color: var(--muted);">Optional. JPG, PNG, or WEBP up to 8 MB.</p>
                    @error('image') <p class="mf-error">{{ $message }}</p> @enderror
                    <div id="image-preview-wrapper" class="mt-3 hidden">
                        <img id="image-preview" alt="Preview" class="h-40 w-40 object-cover rounded" style="border: 1px solid var(--line);" />
                    </div>
                </div>

                <div class="mb-4">
                    <label for="weight_kg" class="mf-label">Weight (kg)</label>
                    <input type="number" name="weight_kg" id="weight_kg" value="{{ old('weight_kg', $source?->weight_kg) }}" step="0.001" min="0" class="mf-input"
                           placeholder="e.g., 1.000, 0.250">
                    @error('weight_kg') <p class="mf-error">{{ $message }}</p> @enderror
                    <p class="text-[12px] mt-1" style="color: var(--muted);">For variable-weight items, this is the expected/average weight.</p>
                </div>

                <div class="mb-4 p-3 rounded-md" style="background: var(--warn-soft); border: 1px solid var(--warn-soft-border);">
                    <h4 class="text-[13px] font-semibold mb-2" style="color: var(--warn-ink);">Weight & pricing</h4>
                    <div class="space-y-2">
                        <label class="flex items-start">
                            <input type="checkbox" name="is_variable_weight" value="1" {{ old('is_variable_weight', $source?->is_variable_weight) ? 'checked' : '' }} class="mt-0.5 mf-checkbox">
                            <span class="ml-2">
                                <span class="text-[13px] font-medium">Variable weight</span>
                                <span class="block text-[12px]" style="color: var(--muted);">Requires weighing at fulfillment (e.g., cheese wheels).</span>
                            </span>
                        </label>
                        <label class="flex items-start">
                            <input type="checkbox" name="is_priced_by_weight" value="1" {{ old('is_priced_by_weight', $source?->is_priced_by_weight) ? 'checked' : '' }} class="mt-0.5 mf-checkbox">
                            <span class="ml-2">
                                <span class="text-[13px] font-medium">Priced by weight</span>
                                <span class="block text-[12px]" style="color: var(--muted);">Base price is per kg. Uncheck for fixed unit pricing.</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="base_price" class="mf-label">Base price <span style="color: var(--danger);">*</span></label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-[13px]" style="color: var(--muted);">€</span>
                        <input type="number" name="base_price" id="base_price" value="{{ old('base_price', $source?->base_price) }}" required step="0.01" min="0" class="mf-input pl-7" placeholder="0.00">
                    </div>
                    @error('base_price') <p class="mf-error">{{ $message }}</p> @enderror
                </div>

                <div class="mb-5">
                    <label class="inline-flex items-center">
                        <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }} class="mf-checkbox">
                        <span class="ml-2 text-[13px]">Active (available for orders)</span>
                    </label>
                </div>

                <div class="flex items-center justify-end gap-2 pt-4" style="border-top: 1px solid var(--line-2);">
                    <a href="{{ route('products.show', $product) }}" class="mf-btn-ghost">Cancel</a>
                    <button type="submit" class="mf-btn-primary">Create variant</button>
                </div>
            </form>
        </div>
        </main>
    </div>

    <script>
        function previewVariantImage(event) {
            const file = event.target.files && event.target.files[0];
            const wrapper = document.getElementById('image-preview-wrapper');
            const img = document.getElementById('image-preview');
            if (!file) {
                wrapper.classList.add('hidden');
                img.src = '';
                return;
            }
            img.src = URL.createObjectURL(file);
            wrapper.classList.remove('hidden');
        }
    </script>
</x-app-layout>
