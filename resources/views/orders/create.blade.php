<x-app-layout>
    <x-slot name="header">New order</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Create new order</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Pick a customer and add line items.</div>
            </div>
            <a href="{{ route('orders.index') }}" class="mf-btn-ghost">← All orders</a>
        </div>

        <div class="mf-panel">
            <form method="POST" action="{{ route('orders.store') }}" id="orderForm" class="p-5">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                    <div>
                        <x-input-label for="customer_id" :value="__('Customer')" />
                        <select id="customer_id" name="customer_id" class="mf-select" required>
                            <option value="">Select customer</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}" {{ old('customer_id') == $customer->id ? 'selected' : '' }}>
                                    {{ $customer->name }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('customer_id')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="order_date" :value="__('Order date')" />
                        <x-text-input id="order_date" name="order_date" type="date"
                            :value="old('order_date', date('Y-m-d'))" required />
                        <x-input-error :messages="$errors->get('order_date')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="delivery_date" :value="__('Delivery date')" />
                        <x-text-input id="delivery_date" name="delivery_date" type="date" :value="old('delivery_date')" />
                        <x-input-error :messages="$errors->get('delivery_date')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="delivery_address" :value="__('Delivery address')" />
                        <textarea id="delivery_address" name="delivery_address" rows="3" class="mf-textarea">{{ old('delivery_address') }}</textarea>
                        <x-input-error :messages="$errors->get('delivery_address')" class="mt-1" />
                    </div>
                </div>

                <div class="mb-5">
                    <x-input-label for="notes" :value="__('Notes')" />
                    <textarea id="notes" name="notes" rows="3" class="mf-textarea">{{ old('notes') }}</textarea>
                    <x-input-error :messages="$errors->get('notes')" class="mt-1" />
                </div>

                <div class="mb-5">
                    <h3 class="text-[14px] font-semibold mb-3">Order items</h3>

                    <div id="orderItems" class="space-y-3">
                        @if(old('items'))
                            @foreach(old('items') as $index => $item)
                                <div class="order-item relative rounded-lg p-3" style="background: var(--bg); border: 1px solid var(--line);">
                                    <button type="button" class="remove-item absolute top-2 right-2 grid place-items-center" style="width: 22px; height: 22px; border-radius: 11px; background: var(--line); color: var(--ink-2); font-size: 12px;">×</button>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <div>
                                            <label class="mf-label">Product</label>
                                            <select name="items[{{ $index }}][product_variant_id]" class="product-select mf-select">
                                                <option value="">Select product</option>
                                                @foreach($productVariants as $productName => $variants)
                                                    <optgroup label="{{ $productName }}">
                                                        @foreach($variants as $variant)
                                                            <option value="{{ $variant->id }}" data-price="{{ $variant->estimated_unit_price }}"
                                                                {{ $item['product_variant_id'] == $variant->id ? 'selected' : '' }}>
                                                                {{ $variant->name }} ({{ $variant->price_label }})
                                                            </option>
                                                        @endforeach
                                                    </optgroup>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mf-label">Quantity</label>
                                            <input type="number" name="items[{{ $index }}][quantity]" min="1" class="quantity-input mf-input" value="{{ $item['quantity'] }}">
                                        </div>
                                        <div>
                                            <label class="mf-label">Line total</label>
                                            <input type="text" class="line-total mf-input font-mono" style="background: var(--line-2);" readonly>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @else
                            <div class="order-item relative rounded-lg p-3" style="background: var(--bg); border: 1px solid var(--line);">
                                <button type="button" class="remove-item absolute top-2 right-2 grid place-items-center" style="width: 22px; height: 22px; border-radius: 11px; background: var(--line); color: var(--ink-2); font-size: 12px;">×</button>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                    <div>
                                        <label class="mf-label">Product</label>
                                        <select name="items[0][product_variant_id]" class="product-select mf-select">
                                            <option value="">Select product</option>
                                            @foreach($productVariants as $productName => $variants)
                                                <optgroup label="{{ $productName }}">
                                                    @foreach($variants as $variant)
                                                        <option value="{{ $variant->id }}" data-price="{{ $variant->estimated_unit_price }}">
                                                            {{ $variant->name }} ({{ $variant->price_label }})
                                                        </option>
                                                    @endforeach
                                                </optgroup>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mf-label">Quantity</label>
                                        <input type="number" name="items[0][quantity]" min="1" class="quantity-input mf-input">
                                    </div>
                                    <div>
                                        <label class="mf-label">Line total</label>
                                        <input type="text" class="line-total mf-input font-mono" style="background: var(--line-2);" readonly>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <x-input-error :messages="$errors->get('items')" class="mt-2" />
                </div>

                <div class="mb-5 mf-panel">
                    <div class="px-4 py-3 flex justify-between items-center">
                        <span class="text-[13px] font-semibold">Order total</span>
                        <span id="orderTotal" class="font-mono text-[18px] font-semibold">€0.00</span>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <a href="{{ route('orders.index') }}" class="mf-btn-ghost">Cancel</a>
                    <x-primary-button>{{ __('Create order') }}</x-primary-button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let itemIndex = {{ old('items') ? count(old('items')) : 1 }};

        function appendEmptyItem() {
            const orderItems = document.getElementById('orderItems');
            const newItem = createOrderItem(itemIndex);
            orderItems.appendChild(newItem);
            itemIndex++;
            updateOrderTotal();
        }

        function isLastItem(itemEl) {
            const items = document.querySelectorAll('#orderItems .order-item');
            return items.length > 0 && items[items.length - 1] === itemEl;
        }

        function createOrderItem(index) {
            const div = document.createElement('div');
            div.className = 'order-item relative rounded-lg p-3';
            div.style.background = 'var(--bg)';
            div.style.border = '1px solid var(--line)';
            div.innerHTML = `
                <button type="button" class="remove-item absolute top-2 right-2 grid place-items-center" style="width: 22px; height: 22px; border-radius: 11px; background: var(--line); color: var(--ink-2); font-size: 12px;">×</button>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="mf-label">Product</label>
                        <select name="items[${index}][product_variant_id]" class="product-select mf-select">
                            <option value="">Select product</option>
                            @foreach($productVariants as $productName => $variants)
                                <optgroup label="{{ $productName }}">
                                    @foreach($variants as $variant)
                                        <option value="{{ $variant->id }}" data-price="{{ $variant->estimated_unit_price }}">
                                            {{ $variant->name }} ({{ $variant->price_label }})
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mf-label">Quantity</label>
                        <input type="number" name="items[${index}][quantity]" min="1" class="quantity-input mf-input">
                    </div>
                    <div>
                        <label class="mf-label">Line total</label>
                        <input type="text" class="line-total mf-input font-mono" style="background: var(--line-2);" readonly>
                    </div>
                </div>
            `;

            const removeBtn = div.querySelector('.remove-item');
            removeBtn.addEventListener('click', function() {
                div.remove();
                updateOrderTotal();
                ensureTrailingEmptyItem();
            });

            const productSelect = div.querySelector('.product-select');
            const quantityInput = div.querySelector('.quantity-input');
            const lineTotalInput = div.querySelector('.line-total');

            productSelect.addEventListener('change', function() {
                updateLineTotal(this, quantityInput, lineTotalInput);
                if (this.value && isLastItem(div)) {
                    appendEmptyItem();
                }
            });

            quantityInput.addEventListener('input', function() {
                updateLineTotal(productSelect, this, lineTotalInput);
            });

            return div;
        }

        function ensureTrailingEmptyItem() {
            const items = document.querySelectorAll('#orderItems .order-item');
            if (items.length === 0) {
                appendEmptyItem();
                return;
            }
            const last = items[items.length - 1];
            if (last.querySelector('.product-select').value) {
                appendEmptyItem();
            }
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

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.order-item').forEach(function(item) {
                const removeBtn = item.querySelector('.remove-item');
                const productSelect = item.querySelector('.product-select');
                const quantityInput = item.querySelector('.quantity-input');
                const lineTotalInput = item.querySelector('.line-total');

                removeBtn.addEventListener('click', function() {
                    item.remove();
                    updateOrderTotal();
                    ensureTrailingEmptyItem();
                });

                productSelect.addEventListener('change', function() {
                    updateLineTotal(this, quantityInput, lineTotalInput);
                    if (this.value && isLastItem(item)) {
                        appendEmptyItem();
                    }
                });

                quantityInput.addEventListener('input', function() {
                    updateLineTotal(productSelect, this, lineTotalInput);
                });

                if (productSelect.value && quantityInput.value) {
                    updateLineTotal(productSelect, quantityInput, lineTotalInput);
                }
            });

            ensureTrailingEmptyItem();
            updateOrderTotal();
        });
    </script>
</x-app-layout>
