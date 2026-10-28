<x-app-layout>
    <x-slot name="header">Customers</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Customers</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Trade accounts, online portal links, and credit terms.</div>
            </div>
            <a href="{{ route('customers.create') }}" class="mf-btn-primary">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                Add customer
            </a>
        </div>

        <div class="mf-panel mb-4">
            <form method="GET" action="{{ route('customers.index') }}" class="flex flex-wrap gap-3 items-end p-4">
                <div class="flex-1 min-w-[200px]">
                    <label for="search" class="mf-label">Search</label>
                    <input type="text" name="search" id="search" value="{{ request('search') }}"
                           placeholder="Search by name or email…" class="mf-input"
                           x-data
                           x-init="if ($el.value) { $el.focus(); $el.setSelectionRange($el.value.length, $el.value.length) }"
                           x-on:input.debounce.500ms="$el.form.requestSubmit()">
                </div>
                <div>
                    <label for="is_active" class="mf-label">Status</label>
                    <select name="is_active" id="is_active" class="mf-select" onchange="this.form.requestSubmit()">
                        <option value="">All customers</option>
                        <option value="1" {{ request('is_active') === '1' ? 'selected' : '' }}>Active only</option>
                        <option value="0" {{ request('is_active') === '0' ? 'selected' : '' }}>Inactive only</option>
                    </select>
                </div>
                @if (config('services.mossorders.ui_enabled'))
                    <div>
                        <label for="has_online_account" class="mf-label">Online account</label>
                        <select name="has_online_account" id="has_online_account" class="mf-select" onchange="this.form.requestSubmit()">
                            <option value="">All</option>
                            <option value="1" {{ request('has_online_account') === '1' ? 'selected' : '' }}>Linked</option>
                            <option value="0" {{ request('has_online_account') === '0' ? 'selected' : '' }}>Not linked</option>
                        </select>
                    </div>
                @endif
                <button type="submit" class="mf-btn-secondary">Search</button>
                @if(request()->hasAny(['search', 'is_active', 'has_online_account']))
                    <a href="{{ route('customers.index') }}" class="mf-btn-ghost">Clear</a>
                @endif
            </form>
        </div>

        <div class="mf-panel">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th class="mf-th">Name</th>
                            <th class="mf-th">Contact</th>
                            <th class="mf-th">Location</th>
                            <th class="mf-th">Credit limit</th>
                            <th class="mf-th">Orders</th>
                            <th class="mf-th">Status</th>
                            <th class="mf-th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customers as $customer)
                            <tr style="border-top: 1px solid var(--line-2);">
                                <td class="mf-td">
                                    <div class="font-medium">{{ $customer->name }}</div>
                                    @if(config('services.mossorders.ui_enabled') && $customer->hasOnlineAccount())
                                        <span class="mf-tag mf-tag-info mt-0.5">Online · {{ $customer->mossorders_user_id }}</span>
                                    @endif
                                </td>
                                <td class="mf-td">
                                    <div>{{ $customer->email ?: '—' }}</div>
                                    @if($customer->phone)
                                        <div class="text-[11.5px] mt-0.5 font-mono" style="color: var(--muted);">{{ $customer->phone }}</div>
                                    @endif
                                </td>
                                <td class="mf-td">
                                    <div>{{ $customer->city }}</div>
                                    <div class="text-[11.5px] mt-0.5" style="color: var(--muted);">{{ $customer->country }}</div>
                                </td>
                                <td class="mf-td font-mono">{{ (float) $customer->credit_limit > 0 ? '€'.number_format($customer->credit_limit, 2) : 'No limit' }}</td>
                                <td class="mf-td font-mono" style="color: var(--muted);">
                                    {{ $customer->orders_count }}
                                </td>
                                <td class="mf-td">
                                    @if($customer->is_active)
                                        <span class="mf-tag mf-tag-accent">Active</span>
                                    @else
                                        <span class="mf-tag mf-tag-danger">Inactive</span>
                                    @endif
                                </td>
                                <td class="mf-td text-right">
                                    <a href="{{ route('customers.show', $customer) }}" class="mf-link">View</a>
                                    <span style="color: var(--faint);"> · </span>
                                    <a href="{{ route('customers.edit', $customer) }}" class="mf-link">Edit</a>
                                    @if($customer->orders_count === 0)
                                        <span style="color: var(--faint);"> · </span>
                                        <form action="{{ route('customers.destroy', array_merge(['customer' => $customer], request()->only('search', 'is_active'))) }}" method="POST" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="mf-link" style="color: var(--danger);"
                                                    data-confirm-title="Delete this customer?" data-confirm="This deletes the customer and their special prices. A customer with orders can't be deleted — set them inactive instead." data-confirm-label="Delete" data-confirm-danger>Delete</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr style="border-top: 1px solid var(--line-2);">
                                <td colspan="7" class="mf-td text-center py-10" style="color: var(--muted);">
                                    No customers found. <a href="{{ route('customers.create') }}" class="mf-link">Create one now</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($customers->hasPages())
                <div class="px-4 py-3" style="border-top: 1px solid var(--line-2);">
                    {{ $customers->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
