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

        @php
            $refMap = $customers->pluck('requires_reference', 'id');
            $initialShowRef = old('customer_reference') || ($refMap[old('customer_id')] ?? false);
        @endphp
        <div class="mf-panel">
            <form method="POST" action="{{ route('orders.store') }}" id="orderForm" class="p-5"
                x-data="{ showRef: {{ $initialShowRef ? 'true' : 'false' }}, requiresRef: @js($refMap) }">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                    <div>
                        <x-input-label for="customer_id" :value="__('Customer')" />
                        <select id="customer_id" name="customer_id" class="mf-select" required
                            @change="if (requiresRef[$event.target.value]) showRef = true">
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
                        <p id="deliveryDateHint" class="text-[12px] mt-1" style="color: var(--muted);"></p>
                        <x-input-error :messages="$errors->get('delivery_date')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="delivery_address" :value="__('Delivery address (if different)')" />
                        <textarea id="delivery_address" name="delivery_address" rows="2" class="mf-textarea">{{ old('delivery_address') }}</textarea>
                        <p class="text-[12px] mt-1" style="color: var(--muted);">Leave blank to deliver to the customer's address.</p>
                        <x-input-error :messages="$errors->get('delivery_address')" class="mt-1" />
                    </div>
                </div>

                <div class="mb-5">
                    <x-input-label for="notes" :value="__('Notes')" />
                    <textarea id="notes" name="notes" rows="3" class="mf-textarea">{{ old('notes') }}</textarea>
                    <p class="text-[12px] mt-1" style="color: var(--muted);">Internal — not printed on customer documents.</p>
                    <x-input-error :messages="$errors->get('notes')" class="mt-1" />
                </div>

                <div class="mb-5">
                    <button type="button" class="mf-btn-ghost text-[12px]" x-show="!showRef" @click="showRef = true">+ Customer ref</button>
                    <div x-show="showRef" x-cloak>
                        <x-input-label for="customer_reference" :value="__('Customer ref')" />
                        <x-text-input id="customer_reference" name="customer_reference" type="text" class="block w-full"
                            :value="old('customer_reference')" maxlength="255" placeholder="e.g. their PO number" />
                        <p class="mt-1 text-[12px]" style="color: var(--muted);">Optional — the customer's own reference / purchase-order number.</p>
                        <x-input-error :messages="$errors->get('customer_reference')" class="mt-1" />
                    </div>
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
                                                            <option value="{{ $variant->id }}" data-price="{{ $variant->estimated_unit_price }}" data-picker="{{ $variant->picker_label }}" data-by-weight="{{ $variant->is_priced_by_weight ? 1 : 0 }}" data-weight="{{ $variant->is_priced_by_weight ? (float) $variant->weight_kg : '' }}"
                                                                {{ $item['product_variant_id'] == $variant->id ? 'selected' : '' }}>
                                                                {{ $variant->picker_label }} ({{ $variant->price_label }})
                                                            </option>
                                                        @endforeach
                                                    </optgroup>
                                                @endforeach
                                            </select>
                                            <x-input-error :messages="$errors->get('items.'.$index.'.product_variant_id')" class="mt-1" />
                                        </div>
                                        <div>
                                            <label class="mf-label">Quantity</label>
                                            <input type="number" name="items[{{ $index }}][quantity]" min="1" class="quantity-input mf-input" value="{{ $item['quantity'] }}">
                                            <x-input-error :messages="$errors->get('items.'.$index.'.quantity')" class="mt-1" />
                                        </div>
                                        <div>
                                            <label class="mf-label">Line total</label>
                                            <input type="text" class="line-total mf-input font-mono" style="background: var(--line-2);" readonly tabindex="-1">
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
                                                        <option value="{{ $variant->id }}" data-price="{{ $variant->estimated_unit_price }}" data-picker="{{ $variant->picker_label }}" data-by-weight="{{ $variant->is_priced_by_weight ? 1 : 0 }}" data-weight="{{ $variant->is_priced_by_weight ? (float) $variant->weight_kg : '' }}">
                                                            {{ $variant->picker_label }} ({{ $variant->price_label }})
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
                                        <input type="text" class="line-total mf-input font-mono" style="background: var(--line-2);" readonly tabindex="-1">
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <x-input-error :messages="$errors->get('items')" class="mt-2" />
                </div>

                {{-- Priced at this customer's rates, with their delivery charge —
                     the same figures store() will save (E2E F-20). --}}
                <div class="mb-5 mf-panel">
                    <div class="px-4 pt-3 flex justify-between items-center text-[13px]">
                        <span>Items</span>
                        <span id="orderItemsTotal" class="font-mono">€0.00</span>
                    </div>
                    <div class="px-4 pt-1 flex justify-between items-center text-[13px]">
                        <span>Delivery (incl. VAT) <span id="orderDeliveryNote" class="text-[11.5px]" style="color: var(--muted);"></span></span>
                        <span id="orderDelivery" class="font-mono">€0.00</span>
                    </div>
                    <div class="px-4 py-3 flex justify-between items-center">
                        <span class="text-[13px] font-semibold">Total</span>
                        <span id="orderTotal" class="font-mono text-[18px] font-semibold">€0.00</span>
                    </div>
                    <p id="orderEstimateNote" class="px-4 pb-3 -mt-1 text-[12px]" style="color: var(--muted);" hidden>~ Estimated from nominal weights — the invoice uses the picked weight.</p>
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
            relabelOptions(newItem);
            itemIndex++;
            updateOrderTotal();
            refreshBlankRow();
        }

        // The blank row the form keeps at the end is optional: while it has
        // no product it is dashed and has no × (the server drops it anyway).
        // The first row is never "another" item, so it is left alone.
        function refreshBlankRow() {
            const items = document.querySelectorAll('#orderItems .order-item');
            items.forEach(function(item, i) {
                if (i === 0) {
                    return;
                }
                const optional = i === items.length - 1 && !item.querySelector('.product-select').value;
                item.style.borderStyle = optional ? 'dashed' : 'solid';
                item.querySelector('.remove-item').style.display = optional ? 'none' : '';
            });
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
                            <option value="">Add another item…</option>
                            @foreach($productVariants as $productName => $variants)
                                <optgroup label="{{ $productName }}">
                                    @foreach($variants as $variant)
                                        <option value="{{ $variant->id }}" data-price="{{ $variant->estimated_unit_price }}" data-picker="{{ $variant->picker_label }}" data-by-weight="{{ $variant->is_priced_by_weight ? 1 : 0 }}" data-weight="{{ $variant->is_priced_by_weight ? (float) $variant->weight_kg : '' }}">
                                            {{ $variant->picker_label }} ({{ $variant->price_label }})
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
                        <input type="text" class="line-total mf-input font-mono" style="background: var(--line-2);" readonly tabindex="-1">
                    </div>
                </div>
            `;

            const removeBtn = div.querySelector('.remove-item');
            removeBtn.addEventListener('click', function() {
                div.remove();
                updateOrderTotal();
                ensureTrailingEmptyItem();
                refreshBlankRow();
            });

            const productSelect = div.querySelector('.product-select');
            const quantityInput = div.querySelector('.quantity-input');
            const lineTotalInput = div.querySelector('.line-total');

            productSelect.addEventListener('change', function() {
                updateLineTotal(this, quantityInput, lineTotalInput);
                if (this.value && isLastItem(div)) {
                    appendEmptyItem();
                }
                refreshBlankRow();
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

        // Per-customer special prices (variant id => estimated unit price) and
        // delivery settings, so the preview matches what will be saved.
        const customerPrices = @json($customerPrices);
        const customerCharges = @json($customerCharges);
        // Once per drop: dates (next 14 days) whose drop already carries the charge.
        const customerChargedDates = @json($customerChargedDates);
        const customerPriceLabels = @json($customerPriceLabels);
        const customerRunDates = @json($customerRunDates);
        const customerRuns = @json($customerRuns);

        // Option text shows the customer's price where they have a special;
        // the server-rendered label (standard price) otherwise.
        function relabelOptions(root) {
            const labels = customerPriceLabels[currentCustomerId()] || {};
            (root || document).querySelectorAll('.product-select option[data-picker]').forEach(function(option) {
                if (option.dataset.defaultLabel === undefined) {
                    option.dataset.defaultLabel = option.textContent.trim();
                }
                const special = labels[option.value];
                option.textContent = special
                    ? option.dataset.picker + ' (' + special + ' · special)'
                    : option.dataset.defaultLabel;
            });
        }

        // Delivery date follows the customer's next run, but never over a
        // date the operator typed: only an empty field, or one still holding
        // the previous auto-fill, is (re)filled.
        let autoDeliveryDate = null;
        function fillDeliveryDate() {
            const input = document.getElementById('delivery_date');
            if (! input.value || input.value === autoDeliveryDate) {
                const next = customerRunDates[currentCustomerId()] || '';
                input.value = next;
                autoDeliveryDate = next || null;
            }
            updateDeliveryHint();
        }

        // Says which run the date came from (D22) — the default itself is unchanged.
        function updateDeliveryHint() {
            const hint = document.getElementById('deliveryDateHint');
            const customerId = currentCustomerId();
            if (! customerId) {
                hint.textContent = '';
                return;
            }
            const run = customerRuns[customerId];
            if (! run) {
                hint.textContent = 'No delivery run — pick a date.';
                return;
            }
            const input = document.getElementById('delivery_date');
            hint.textContent = run + (autoDeliveryDate && input.value === autoDeliveryDate ? ' — next run date filled in' : '');
        }

        function currentCustomerId() {
            return document.getElementById('customer_id').value;
        }

        function unitPrice(option) {
            if (! option || ! option.value) {
                return 0;
            }
            const special = customerPrices[currentCustomerId()]?.[option.value];

            return special !== undefined ? parseFloat(special) : parseFloat(option.dataset.price);
        }

        function updateLineTotal(productSelect, quantityInput, lineTotalInput) {
            const selectedOption = productSelect.selectedOptions[0];
            const price = unitPrice(selectedOption);
            const quantity = parseInt(quantityInput.value) || 0;
            const lineTotal = price * quantity;

            // Weight-priced lines are an estimate from the nominal weight (D24).
            if (isByWeight(selectedOption)) {
                const kg = parseFloat((parseFloat(selectedOption.dataset.weight || 0) * quantity).toFixed(3));
                lineTotalInput.value = '~€' + lineTotal.toFixed(2) + ' · est. ' + kg + ' kg';
            } else {
                lineTotalInput.value = '€' + lineTotal.toFixed(2);
            }
            updateOrderTotal();
        }

        function isByWeight(option) {
            return !! option && !! option.value && option.dataset.byWeight === '1';
        }

        function updateOrderTotal() {
            let total = 0;
            let estimated = false;
            document.querySelectorAll('.order-item').forEach(function(item) {
                const productSelect = item.querySelector('.product-select');
                const quantityInput = item.querySelector('.quantity-input');

                if (productSelect.value && quantityInput.value) {
                    estimated = estimated || isByWeight(productSelect.selectedOptions[0]);
                    const price = unitPrice(productSelect.selectedOptions[0]);
                    const quantity = parseInt(quantityInput.value);
                    total += Math.round(price * quantity * 100) / 100;
                }
            });

            const settings = customerCharges[currentCustomerId()] || { charge: 0, percent: null };
            const chargedOn = (customerChargedDates[currentCustomerId()] || {})[document.getElementById('delivery_date').value];
            const waived = ! settings.percent && parseFloat(settings.charge) > 0 && chargedOn;
            const delivery = total > 0 && ! waived
                ? (settings.percent ? Math.round(total * settings.percent) / 100 : parseFloat(settings.charge) || 0)
                : 0;
            document.getElementById('orderDeliveryNote').textContent = waived ? '(already charged on ' + chargedOn + ' that day)' : '';

            const approx = estimated ? '~' : '';
            document.getElementById('orderItemsTotal').textContent = approx + '€' + total.toFixed(2);
            document.getElementById('orderDelivery').textContent = '€' + delivery.toFixed(2);
            document.getElementById('orderTotal').textContent = approx + '€' + (total + delivery).toFixed(2);
            document.getElementById('orderEstimateNote').hidden = ! estimated;
        }

        // Prices and delivery depend on the customer: re-price every line.
        function repriceAllLines() {
            document.querySelectorAll('.order-item').forEach(function(item) {
                const productSelect = item.querySelector('.product-select');
                const quantityInput = item.querySelector('.quantity-input');
                const lineTotalInput = item.querySelector('.line-total');
                if (productSelect.value && quantityInput.value) {
                    updateLineTotal(productSelect, quantityInput, lineTotalInput);
                }
            });
            updateOrderTotal();
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
                    refreshBlankRow();
                });

                productSelect.addEventListener('change', function() {
                    updateLineTotal(this, quantityInput, lineTotalInput);
                    if (this.value && isLastItem(item)) {
                        appendEmptyItem();
                    }
                    refreshBlankRow();
                });

                quantityInput.addEventListener('input', function() {
                    updateLineTotal(productSelect, this, lineTotalInput);
                });

                if (productSelect.value && quantityInput.value) {
                    updateLineTotal(productSelect, quantityInput, lineTotalInput);
                }
            });

            document.getElementById('customer_id').addEventListener('change', function() {
                relabelOptions();
                fillDeliveryDate();
                repriceAllLines();
            });
            document.getElementById('delivery_date').addEventListener('change', function() {
                updateDeliveryHint();
                updateOrderTotal();
            });
            relabelOptions();
            if (currentCustomerId()) {
                fillDeliveryDate();
            }

            ensureTrailingEmptyItem();
            refreshBlankRow();
            updateOrderTotal();
        });
    </script>
</x-app-layout>
