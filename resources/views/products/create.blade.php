<x-app-layout>
    <x-slot name="header">New product</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Create product</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Add a new product to the catalog.</div>
            </div>
            <a href="{{ route('products.index') }}" class="mf-btn-ghost">← All products</a>
        </div>

        <div class="mf-panel">
            <form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data" class="p-5">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                    <div>
                        <x-input-label for="name" :value="__('Product name')" />
                        <x-text-input id="name" type="text" name="name" :value="old('name')" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="type" :value="__('Product type')" />
                        <select id="type" name="type" class="mf-select" required>
                            <option value="">Select type</option>
                            <option value="milk" {{ old('type') === 'milk' ? 'selected' : '' }}>Milk</option>
                            <option value="yoghurt" {{ old('type') === 'yoghurt' ? 'selected' : '' }}>Yoghurt</option>
                            <option value="cheese" {{ old('type') === 'cheese' ? 'selected' : '' }}>Cheese</option>
                        </select>
                        <x-input-error :messages="$errors->get('type')" class="mt-1" />
                    </div>
                </div>

                <div class="mb-5">
                    <x-input-label for="description" :value="__('Description')" />
                    <textarea id="description" name="description" rows="3" class="mf-textarea">{{ old('description') }}</textarea>
                    <x-input-error :messages="$errors->get('description')" class="mt-1" />
                </div>

                <div class="mb-5">
                    <x-input-label for="image" :value="__('Product image')" />
                    <input id="image" name="image" type="file" accept="image/*"
                           onchange="previewProductImage(event)"
                           class="block w-full text-[13px]" />
                    <p class="text-[12px] mt-1" style="color: var(--muted);">JPG, PNG, or WEBP up to 8 MB. On mobile you can take a photo.</p>
                    <x-input-error :messages="$errors->get('image')" class="mt-1" />
                    <div id="image-preview-wrapper" class="mt-3 hidden">
                        <img id="image-preview" alt="Preview" class="h-40 w-40 object-cover rounded" style="border: 1px solid var(--line);" />
                    </div>
                </div>

                <div class="mb-5" id="maturation-field" style="display: none;">
                    <x-input-label for="maturation_days" :value="__('Maturation days')" />
                    <x-text-input id="maturation_days" type="number" name="maturation_days" :value="old('maturation_days')" min="1" />
                    <x-input-error :messages="$errors->get('maturation_days')" class="mt-1" />
                    <p class="text-[12px] mt-1" style="color: var(--muted);">Number of days required for cheese maturation.</p>
                </div>

                <div class="mb-5">
                    <div class="flex items-center">
                        <input id="is_active" name="is_active" type="checkbox" value="1"
                               {{ old('is_active', true) ? 'checked' : '' }} class="mf-checkbox">
                        <label for="is_active" class="ml-2 text-[13px]">Active</label>
                    </div>
                    <x-input-error :messages="$errors->get('is_active')" class="mt-1" />
                </div>

                <div class="flex items-center justify-end gap-2 pt-4" style="border-top: 1px solid var(--line-2);">
                    <a href="{{ route('products.index') }}" class="mf-btn-ghost">Cancel</a>
                    <x-primary-button>{{ __('Create product') }}</x-primary-button>
                </div>
            </form>
        </div>
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

        if (document.getElementById('type').value === 'cheese') {
            document.getElementById('maturation-field').style.display = 'block';
        }

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
