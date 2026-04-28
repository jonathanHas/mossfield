<x-app-layout>
    <x-slot name="header">Edit order {{ $order->order_number }}</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <div class="text-[12px] font-mono" style="color: var(--muted);">{{ $order->order_date->format('d/m/Y') }}</div>
                <h1 class="mt-0.5 text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">
                    Edit order <span class="font-mono text-[20px]">{{ $order->order_number }}</span>
                </h1>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('orders.index') }}" class="mf-btn-ghost">← All orders</a>
                <a href="{{ route('orders.show', $order) }}" class="mf-btn-secondary">View</a>
            </div>
        </div>

        <div class="mf-panel mb-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 px-4 py-3" style="background: var(--bg); border-bottom: 1px solid var(--line-2);">
                <div>
                    <div class="text-[11.5px] uppercase font-medium" style="color: var(--muted); letter-spacing: 0.4px;">Customer</div>
                    <div class="mt-0.5 text-[14px] font-medium">{{ $order->customer->name }}</div>
                </div>
                <div>
                    <div class="text-[11.5px] uppercase font-medium" style="color: var(--muted); letter-spacing: 0.4px;">Order date</div>
                    <div class="mt-0.5 text-[14px] font-mono">{{ $order->order_date->format('d/m/Y') }}</div>
                </div>
                <div>
                    <div class="text-[11.5px] uppercase font-medium" style="color: var(--muted); letter-spacing: 0.4px;">Total</div>
                    <div class="mt-0.5 text-[14px] font-mono font-medium">€{{ number_format($order->total_amount, 2) }}</div>
                </div>
            </div>

            <form method="POST" action="{{ route('orders.update', $order) }}" class="p-5">
                @csrf
                @method('PATCH')

                <input type="hidden" name="order_date" value="{{ $order->order_date->format('Y-m-d') }}">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                    <div>
                        <x-input-label for="status" :value="__('Order status')" />
                        <select id="status" name="status" class="mf-select" required>
                            @foreach (['pending','confirmed','preparing','ready','dispatched','delivered','cancelled'] as $s)
                                <option value="{{ $s }}" {{ $order->status == $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('status')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="payment_status" :value="__('Payment status')" />
                        <select id="payment_status" name="payment_status" class="mf-select" required>
                            @foreach (['pending','paid','partial','overdue'] as $s)
                                <option value="{{ $s }}" {{ $order->payment_status == $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('payment_status')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="delivery_date" :value="__('Delivery date')" />
                        <x-text-input id="delivery_date" name="delivery_date" type="date"
                            :value="old('delivery_date', $order->delivery_date?->format('Y-m-d'))" />
                        <x-input-error :messages="$errors->get('delivery_date')" class="mt-1" />
                    </div>
                </div>

                <div class="mb-5">
                    <x-input-label for="delivery_address" :value="__('Delivery address')" />
                    <textarea id="delivery_address" name="delivery_address" rows="3" class="mf-textarea">{{ old('delivery_address', $order->delivery_address) }}</textarea>
                    <x-input-error :messages="$errors->get('delivery_address')" class="mt-1" />
                </div>

                <div class="mb-5">
                    <x-input-label for="notes" :value="__('Order notes')" />
                    <textarea id="notes" name="notes" rows="4" class="mf-textarea">{{ old('notes', $order->notes) }}</textarea>
                    <x-input-error :messages="$errors->get('notes')" class="mt-1" />
                </div>

                <div class="mb-5">
                    <h3 class="text-[14px] font-semibold mb-3">Order items</h3>
                    <div class="rounded-lg overflow-hidden" style="border: 1px solid var(--line);">
                        <table class="w-full border-collapse text-[13px]">
                            <thead>
                                <tr>
                                    <th class="mf-th">Product</th>
                                    <th class="mf-th">Unit price</th>
                                    <th class="mf-th">Quantity</th>
                                    <th class="mf-th text-right">Line total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($order->orderItems as $item)
                                    <tr style="border-top: 1px solid var(--line-2);">
                                        <td class="mf-td">
                                            <div class="font-medium">{{ $item->productVariant->product->name }}</div>
                                            <div class="text-[11.5px] mt-0.5" style="color: var(--muted);">{{ $item->productVariant->name }}</div>
                                        </td>
                                        <td class="mf-td font-mono">€{{ number_format($item->unit_price, 2) }}{{ $item->isPricedByWeight() ? '/kg' : '' }}</td>
                                        <td class="mf-td font-mono">{{ $item->quantity_ordered }}</td>
                                        <td class="mf-td font-mono font-medium text-right">€{{ number_format($item->line_total, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr style="border-top: 1px solid var(--line);">
                                    <td colspan="3" class="px-4 py-3 text-right font-medium">Order total</td>
                                    <td class="px-4 py-3 text-right font-mono font-semibold text-[15px]">€{{ number_format($order->total_amount, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="text-[12px] mt-2" style="color: var(--muted);">
                        Order items can't be modified after creation. Create a new order if items need to change.
                    </p>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <a href="{{ route('orders.show', $order) }}" class="mf-btn-ghost">Cancel</a>
                    <x-primary-button>{{ __('Update order') }}</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
