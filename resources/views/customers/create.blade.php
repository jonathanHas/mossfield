<x-app-layout>
    <x-slot name="header">New customer</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Add a customer</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Account, address and trade terms.</div>
            </div>
            <a href="{{ route('customers.index') }}" class="mf-btn-ghost">← All customers</a>
        </div>

        <div class="mf-panel">
            <form method="POST" action="{{ route('customers.store') }}" class="p-5">
                @csrf

                <h3 class="text-[14px] font-semibold mb-3">Basic information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="md:col-span-2">
                        <label for="name" class="mf-label">Customer name <span style="color: var(--danger);">*</span></label>
                        <input type="text" name="name" id="name" value="{{ old('name') }}" required class="mf-input">
                        @error('name') <p class="mf-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="email" class="mf-label">Email <span style="color: var(--danger);">*</span></label>
                        <input type="email" name="email" id="email" value="{{ old('email') }}" required class="mf-input">
                        @error('email') <p class="mf-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="phone" class="mf-label">Phone</label>
                        <input type="text" name="phone" id="phone" value="{{ old('phone') }}" class="mf-input">
                        @error('phone') <p class="mf-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <h3 class="text-[14px] font-semibold mb-3">Address</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="md:col-span-2">
                        <label for="address" class="mf-label">Street address <span style="color: var(--danger);">*</span></label>
                        <textarea name="address" id="address" rows="2" required class="mf-textarea">{{ old('address') }}</textarea>
                        @error('address') <p class="mf-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="city" class="mf-label">City <span style="color: var(--danger);">*</span></label>
                        <input type="text" name="city" id="city" value="{{ old('city') }}" required class="mf-input">
                        @error('city') <p class="mf-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="postal_code" class="mf-label">Postal code <span style="color: var(--danger);">*</span></label>
                        <input type="text" name="postal_code" id="postal_code" value="{{ old('postal_code') }}" required class="mf-input">
                        @error('postal_code') <p class="mf-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="country" class="mf-label">Country <span style="color: var(--danger);">*</span></label>
                        <input type="text" name="country" id="country" value="{{ old('country', 'Ireland') }}" required class="mf-input">
                        @error('country') <p class="mf-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <h3 class="text-[14px] font-semibold mb-3">Business terms</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div>
                        <label for="credit_limit" class="mf-label">Credit limit (€) <span style="color: var(--danger);">*</span></label>
                        <input type="number" name="credit_limit" id="credit_limit" value="{{ old('credit_limit', '0.00') }}"
                               step="0.01" min="0" required class="mf-input">
                        @error('credit_limit') <p class="mf-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="payment_terms" class="mf-label">Payment terms <span style="color: var(--danger);">*</span></label>
                        <select name="payment_terms" id="payment_terms" required class="mf-select">
                            <option value="immediate" {{ old('payment_terms') === 'immediate' ? 'selected' : '' }}>Immediate</option>
                            <option value="net_7" {{ old('payment_terms') === 'net_7' ? 'selected' : '' }}>Net 7 days</option>
                            <option value="net_14" {{ old('payment_terms') === 'net_14' ? 'selected' : '' }}>Net 14 days</option>
                            <option value="net_30" {{ old('payment_terms') === 'net_30' ? 'selected' : '' }}>Net 30 days</option>
                        </select>
                        @error('payment_terms') <p class="mf-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <h3 class="text-[14px] font-semibold mb-3">Online integration</h3>
                <div class="mb-6">
                    <label for="mossorders_user_id" class="mf-label">Mossorders user ID</label>
                    <input type="number" name="mossorders_user_id" id="mossorders_user_id" value="{{ old('mossorders_user_id') }}" class="mf-input">
                    <p class="text-[12px] mt-1" style="color: var(--muted);">
                        Link this customer to their Mossorders online portal account. Leave blank if they don't have one.
                    </p>
                    @error('mossorders_user_id') <p class="mf-error">{{ $message }}</p> @enderror
                </div>

                <h3 class="text-[14px] font-semibold mb-3">Additional</h3>
                <div class="mb-6">
                    <label for="notes" class="mf-label">Notes</label>
                    <textarea name="notes" id="notes" rows="3" class="mf-textarea">{{ old('notes') }}</textarea>
                    @error('notes') <p class="mf-error">{{ $message }}</p> @enderror

                    <div class="flex items-center mt-3">
                        <input type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }} class="mf-checkbox">
                        <label for="is_active" class="ml-2 text-[13px]">Active customer</label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-4" style="border-top: 1px solid var(--line-2);">
                    <a href="{{ route('customers.index') }}" class="mf-btn-ghost">Cancel</a>
                    <button type="submit" class="mf-btn-primary">Create customer</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
