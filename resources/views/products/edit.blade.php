<x-app-layout>
    <x-slot name="header">Edit {{ $product->name }}</x-slot>

    @php
        $listFilters = $listFilters ?? [];
        $productList = $productList ?? collect();
        $listTotal = $listTotal ?? $productList->count();
        $listLimit = $listLimit ?? 50;
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-[320px_1fr]">
        <aside class="hidden lg:flex lg:flex-col" style="border-right: 1px solid var(--line-2); max-height: calc(100vh - var(--header-height, 64px)); overflow-y: auto;">
            @include('products._sibling_list', ['mode' => 'edit'])
        </aside>

        <main class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <div class="text-[12px] font-mono" style="color: var(--muted);">Product</div>
                <h1 class="mt-0.5 text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Edit {{ $product->name }}</h1>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('products.index', $listFilters) }}" class="mf-btn-ghost lg:hidden">← All products</a>
                <a href="{{ route('products.show', array_merge($listFilters, ['product' => $product->id])) }}" class="mf-btn-secondary">View</a>
            </div>
        </div>

        <div class="mf-panel">
            <form method="POST" action="{{ route('products.update', $product) }}" enctype="multipart/form-data" class="p-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                    <div>
                        <x-input-label for="name" :value="__('Product name')" />
                        <x-text-input id="name" type="text" name="name" :value="old('name', $product->name)" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="type" :value="__('Product type')" />
                        <select id="type" name="type" class="mf-select" required>
                            <option value="">Select type</option>
                            <option value="milk" {{ old('type', $product->type) === 'milk' ? 'selected' : '' }}>Milk</option>
                            <option value="yoghurt" {{ old('type', $product->type) === 'yoghurt' ? 'selected' : '' }}>Yoghurt</option>
                            <option value="cheese" {{ old('type', $product->type) === 'cheese' ? 'selected' : '' }}>Cheese</option>
                        </select>
                        <x-input-error :messages="$errors->get('type')" class="mt-1" />
                    </div>
                </div>

                <div class="mb-5">
                    <x-input-label for="description" :value="__('Description')" />
                    <textarea id="description" name="description" rows="3" class="mf-textarea">{{ old('description', $product->description) }}</textarea>
                    <x-input-error :messages="$errors->get('description')" class="mt-1" />
                </div>

                <div class="mb-5">
                    <x-input-label :value="__('Product image')" />
                    @if($product->image_url)
                        <div class="mt-1 flex items-start gap-4">
                            <img src="{{ $product->image_url }}" alt="{{ $product->name }}" class="h-40 w-40 object-cover rounded" style="border: 1px solid var(--line);" />
                            <label class="inline-flex items-center text-[13px]">
                                <input type="checkbox" name="remove_image" value="1" class="mf-checkbox">
                                <span class="ml-2">Remove current image</span>
                            </label>
                        </div>
                    @endif
                    <input id="image" name="image" type="file" accept="image/*"
                           onchange="previewProductImage(event)"
                           class="block mt-2 w-full text-[13px]" />
                    <p class="text-[12px] mt-1" style="color: var(--muted);">Upload a replacement or take a photo with your camera (JPG, PNG, WEBP up to 8 MB).</p>
                    <x-input-error :messages="$errors->get('image')" class="mt-1" />
                    <div id="image-preview-wrapper" class="mt-3 hidden">
                        <p class="text-[12px] mb-1" style="color: var(--muted);">New image preview:</p>
                        <img id="image-preview" alt="Preview" class="h-40 w-40 object-cover rounded" style="border: 1px solid var(--line);" />
                    </div>
                </div>

                <div class="mb-5" id="maturation-field" style="{{ old('type', $product->type) === 'cheese' ? 'display: block;' : 'display: none;' }}">
                    <x-input-label for="maturation_days" :value="__('Maturation days')" />
                    <x-text-input id="maturation_days" type="number" name="maturation_days" :value="old('maturation_days', $product->maturation_days)" min="1" />
                    <x-input-error :messages="$errors->get('maturation_days')" class="mt-1" />
                    <p class="text-[12px] mt-1" style="color: var(--muted);">Number of days required for cheese maturation.</p>
                </div>

                <div class="mb-5">
                    <div class="flex items-center">
                        <input id="is_active" name="is_active" type="checkbox" value="1"
                               {{ old('is_active', $product->is_active) ? 'checked' : '' }} class="mf-checkbox">
                        <label for="is_active" class="ml-2 text-[13px]">Active</label>
                    </div>
                    <x-input-error :messages="$errors->get('is_active')" class="mt-1" />
                </div>

                <div class="flex items-center justify-end gap-2 pt-4" style="border-top: 1px solid var(--line-2);">
                    <a href="{{ route('products.show', array_merge($listFilters, ['product' => $product->id])) }}" class="mf-btn-ghost">Cancel</a>
                    <x-primary-button>{{ __('Update product') }}</x-primary-button>
                </div>
            </form>
        </div>
        </main>
    </div>

    <script>
        document.getElementById('type').addEventListener('change', function() {
            const maturationField = document.getElementById('maturation-field');
            if (this.value === 'cheese') {
                maturationField.style.display = 'block';
            } else {
                maturationField.style.display = 'none';
                document.getElementById('maturation_days').value = '';
            }
        });

        function previewProductImage(event) {
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
