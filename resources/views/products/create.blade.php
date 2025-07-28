<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Create Product') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <form method="POST" action="{{ route('products.store') }}">
                        @csrf

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Name -->
                            <div>
                                <x-input-label for="name" :value="__('Product Name')" />
                                <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus />
                                <x-input-error :messages="$errors->get('name')" class="mt-2" />
                            </div>

                            <!-- Type -->
                            <div>
                                <x-input-label for="type" :value="__('Product Type')" />
                                <select id="type" name="type" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                                    <option value="">Select Type</option>
                                    <option value="milk" {{ old('type') === 'milk' ? 'selected' : '' }}>Milk</option>
                                    <option value="yoghurt" {{ old('type') === 'yoghurt' ? 'selected' : '' }}>Yoghurt</option>
                                    <option value="cheese" {{ old('type') === 'cheese' ? 'selected' : '' }}>Cheese</option>
                                </select>
                                <x-input-error :messages="$errors->get('type')" class="mt-2" />
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="mt-6">
                            <x-input-label for="description" :value="__('Description')" />
                            <textarea id="description" name="description" rows="3" 
                                    class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('description') }}</textarea>
                            <x-input-error :messages="$errors->get('description')" class="mt-2" />
                        </div>

                        <!-- Maturation Days (for cheese) -->
                        <div class="mt-6" id="maturation-field" style="display: none;">
                            <x-input-label for="maturation_days" :value="__('Maturation Days')" />
                            <x-text-input id="maturation_days" class="block mt-1 w-full" type="number" name="maturation_days" :value="old('maturation_days')" min="1" />
                            <x-input-error :messages="$errors->get('maturation_days')" class="mt-2" />
                            <p class="mt-1 text-sm text-gray-600">Number of days required for cheese maturation</p>
                        </div>

                        <!-- Active Status -->
                        <div class="mt-6">
                            <div class="flex items-center">
                                <input id="is_active" name="is_active" type="checkbox" value="1" 
                                       {{ old('is_active', true) ? 'checked' : '' }}
                                       class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                <label for="is_active" class="ml-2 text-sm text-gray-600">Active</label>
                            </div>
                            <x-input-error :messages="$errors->get('is_active')" class="mt-2" />
                        </div>

                        <div class="flex items-center justify-end mt-6">
                            <a href="{{ route('products.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded mr-2">
                                Cancel
                            </a>
                            <x-primary-button>
                                {{ __('Create Product') }}
                            </x-primary-button>
                        </div>
                    </form>
                </div>
            </div>
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

        // Show maturation field if cheese is selected on page load
        if (document.getElementById('type').value === 'cheese') {
            document.getElementById('maturation-field').style.display = 'block';
        }
    </script>
</x-app-layout>