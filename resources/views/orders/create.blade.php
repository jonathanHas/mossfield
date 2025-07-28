<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Create New Order') }}
            </h2>
            <a href="{{ route('orders.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                Back to Orders
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <form method="POST" action="{{ route('orders.store') }}" id="orderForm">
                        @csrf
                        
                        <!-- Order Details -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <x-input-label for="customer_id" :value="__('Customer')" />
                                <select id="customer_id" name="customer_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                                    <option value="">Select Customer</option>
                                    @foreach($customers as $customer)
                                        <option value="{{ $customer->id }}" {{ old('customer_id') == $customer->id ? 'selected' : '' }}>
                                            {{ $customer->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('customer_id')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="order_date" :value="__('Order Date')" />
                                <x-text-input id="order_date" name="order_date" type="date" class="mt-1 block w-full" 
                                    :value="old('order_date', date('Y-m-d'))" required />
                                <x-input-error :messages="$errors->get('order_date')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="delivery_date" :value="__('Delivery Date')" />
                                <x-text-input id="delivery_date" name="delivery_date" type="date" class="mt-1 block w-full" 
                                    :value="old('delivery_date')" />
                                <x-input-error :messages="$errors->get('delivery_date')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="delivery_address" :value="__('Delivery Address')" />
                                <textarea id="delivery_address" name="delivery_address" rows="3" 
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('delivery_address') }}</textarea>
                                <x-input-error :messages="$errors->get('delivery_address')" class="mt-2" />
                            </div>
                        </div>

                        <div class="mb-6">
                            <x-input-label for="notes" :value="__('Notes')" />
                            <textarea id="notes" name="notes" rows="3" 
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('notes') }}</textarea>
                            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                        </div>

                        <!-- Order Items -->
                        <div class="mb-6">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="text-lg font-medium text-gray-900">Order Items</h3>
                                <button type="button" id="addItem" class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                                    Add Item
                                </button>
                            </div>

                            <div id="orderItems" class="space-y-4">
                                @if(old('items'))
                                    @foreach(old('items') as $index => $item)
                                        <div class="order-item bg-gray-50 p-4 rounded-lg relative">
                                            <button type="button" class="remove-item absolute top-2 right-2 bg-red-500 hover:bg-red-700 text-white rounded-full w-6 h-6 flex items-center justify-center text-sm">×</button>
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">Product</label>
                                                    <select name="items[{{ $index }}][product_variant_id]" class="product-select w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                                                        <option value="">Select Product</option>
                                                        @foreach($productVariants as $productName => $variants)
                                                            <optgroup label="{{ $productName }}">
                                                                @foreach($variants as $variant)
                                                                    <option value="{{ $variant->id }}" data-price="{{ $variant->base_price }}" 
                                                                        {{ $item['product_variant_id'] == $variant->id ? 'selected' : '' }}>
                                                                        {{ $variant->name }} (€{{ number_format($variant->base_price, 2) }})
                                                                    </option>
                                                                @endforeach
                                                            </optgroup>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                                                    <input type="number" name="items[{{ $index }}][quantity]" min="1" 
                                                        class="quantity-input w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" 
                                                        value="{{ $item['quantity'] }}" required>
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">Line Total</label>
                                                    <input type="text" class="line-total w-full rounded-md border-gray-300 bg-gray-100" readonly>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                @else
                                    <div class="order-item bg-gray-50 p-4 rounded-lg relative">
                                        <button type="button" class="remove-item absolute top-2 right-2 bg-red-500 hover:bg-red-700 text-white rounded-full w-6 h-6 flex items-center justify-center text-sm">×</button>
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Product</label>
                                                <select name="items[0][product_variant_id]" class="product-select w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                                                    <option value="">Select Product</option>
                                                    @foreach($productVariants as $productName => $variants)
                                                        <optgroup label="{{ $productName }}">
                                                            @foreach($variants as $variant)
                                                                <option value="{{ $variant->id }}" data-price="{{ $variant->base_price }}">
                                                                    {{ $variant->name }} (€{{ number_format($variant->base_price, 2) }})
                                                                </option>
                                                            @endforeach
                                                        </optgroup>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                                                <input type="number" name="items[0][quantity]" min="1" 
                                                    class="quantity-input w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Line Total</label>
                                                <input type="text" class="line-total w-full rounded-md border-gray-300 bg-gray-100" readonly>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <x-input-error :messages="$errors->get('items')" class="mt-2" />
                        </div>

                        <!-- Order Total -->
                        <div class="mb-6 bg-gray-50 p-4 rounded-lg">
                            <div class="flex justify-between items-center text-lg font-medium">
                                <span>Order Total:</span>
                                <span id="orderTotal">€0.00</span>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-4">
                            <a href="{{ route('orders.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                                Cancel
                            </a>
                            <x-primary-button>
                                {{ __('Create Order') }}
                            </x-primary-button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        let itemIndex = {{ old('items') ? count(old('items')) : 1 }};

        document.getElementById('addItem').addEventListener('click', function() {
            const orderItems = document.getElementById('orderItems');
            const newItem = createOrderItem(itemIndex);
            orderItems.appendChild(newItem);
            itemIndex++;
            updateOrderTotal();
        });

        function createOrderItem(index) {
            const div = document.createElement('div');
            div.className = 'order-item bg-gray-50 p-4 rounded-lg relative';
            div.innerHTML = `
                <button type="button" class="remove-item absolute top-2 right-2 bg-red-500 hover:bg-red-700 text-white rounded-full w-6 h-6 flex items-center justify-center text-sm">×</button>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Product</label>
                        <select name="items[${index}][product_variant_id]" class="product-select w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                            <option value="">Select Product</option>
                            @foreach($productVariants as $productName => $variants)
                                <optgroup label="{{ $productName }}">
                                    @foreach($variants as $variant)
                                        <option value="{{ $variant->id }}" data-price="{{ $variant->base_price }}">
                                            {{ $variant->name }} (€{{ number_format($variant->base_price, 2) }})
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                        <input type="number" name="items[${index}][quantity]" min="1" 
                            class="quantity-input w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Line Total</label>
                        <input type="text" class="line-total w-full rounded-md border-gray-300 bg-gray-100" readonly>
                    </div>
                </div>
            `;

            // Add event listeners
            const removeBtn = div.querySelector('.remove-item');
            removeBtn.addEventListener('click', function() {
                if (document.querySelectorAll('.order-item').length > 1) {
                    div.remove();
                    updateOrderTotal();
                }
            });

            const productSelect = div.querySelector('.product-select');
            const quantityInput = div.querySelector('.quantity-input');
            const lineTotalInput = div.querySelector('.line-total');

            productSelect.addEventListener('change', function() {
                updateLineTotal(this, quantityInput, lineTotalInput);
            });

            quantityInput.addEventListener('input', function() {
                updateLineTotal(productSelect, this, lineTotalInput);
            });

            return div;
        }

        function updateLineTotal(productSelect, quantityInput, lineTotalInput) {
            const selectedOption = productSelect.selectedOptions[0];
            const price = selectedOption ? parseFloat(selectedOption.dataset.price) : 0;
            const quantity = parseInt(quantityInput.value) || 0;
            const lineTotal = price * quantity;
            
            lineTotalInput.value = '€' + lineTotal.toFixed(2);
            updateOrderTotal();
        }

        function updateOrderTotal() {
            let total = 0;
            document.querySelectorAll('.order-item').forEach(function(item) {
                const productSelect = item.querySelector('.product-select');
                const quantityInput = item.querySelector('.quantity-input');
                
                if (productSelect.value && quantityInput.value) {
                    const selectedOption = productSelect.selectedOptions[0];
                    const price = parseFloat(selectedOption.dataset.price);
                    const quantity = parseInt(quantityInput.value);
                    total += price * quantity;
                }
            });
            
            document.getElementById('orderTotal').textContent = '€' + total.toFixed(2);
        }

        // Add event listeners to existing items
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.order-item').forEach(function(item) {
                const removeBtn = item.querySelector('.remove-item');
                const productSelect = item.querySelector('.product-select');
                const quantityInput = item.querySelector('.quantity-input');
                const lineTotalInput = item.querySelector('.line-total');

                removeBtn.addEventListener('click', function() {
                    if (document.querySelectorAll('.order-item').length > 1) {
                        item.remove();
                        updateOrderTotal();
                    }
                });

                productSelect.addEventListener('change', function() {
                    updateLineTotal(this, quantityInput, lineTotalInput);
                });

                quantityInput.addEventListener('input', function() {
                    updateLineTotal(productSelect, this, lineTotalInput);
                });

                // Calculate initial line total if values exist
                if (productSelect.value && quantityInput.value) {
                    updateLineTotal(productSelect, quantityInput, lineTotalInput);
                }
            });

            updateOrderTotal();
        });
    </script>
</x-app-layout>