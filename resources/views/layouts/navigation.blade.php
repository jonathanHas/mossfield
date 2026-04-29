@php
    $me = Auth::user();
    // Shared role checks — factory gets the shop-floor nav set, office/admin get everything.
    $canSeeProduction = $me->hasRole('admin', 'office', 'factory');
    $canSeeCustomers = $me->hasRole('admin', 'office');
    $canSeeOrders = $me->hasRole('admin', 'office', 'factory');
    $canSeeAllocation = $me->hasRole('admin', 'office');
    $canSeeOnlineOrders = $me->hasRole('admin', 'office');
    $canSeeUsers = $me->hasRole('admin');

    $groups = [];
    $groups[] = ['label' => null, 'items' => [
        ['id' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'dashboard'],
    ]];

    $salesItems = [];
    if ($canSeeOrders) {
        $salesItems[] = ['id' => 'orders', 'label' => 'Orders', 'route' => 'orders.index', 'match' => 'orders.*', 'icon' => 'order'];
    }
    if ($canSeeOnlineOrders) {
        $salesItems[] = ['id' => 'online', 'label' => 'Online Orders', 'route' => 'online-orders.index', 'match' => 'online-orders.*', 'icon' => 'online'];
    }
    if ($canSeeAllocation) {
        $salesItems[] = ['id' => 'alloc', 'label' => 'Stock Allocation', 'route' => 'order-allocations.index', 'match' => 'order-allocations.*', 'icon' => 'alloc'];
    }
    if ($canSeeCustomers) {
        $salesItems[] = ['id' => 'customers', 'label' => 'Customers', 'route' => 'customers.index', 'match' => 'customers.*', 'icon' => 'customer'];
    }
    if ($salesItems) $groups[] = ['label' => 'Sales', 'items' => $salesItems];

    if ($canSeeProduction) {
        $groups[] = ['label' => 'Production', 'items' => [
            ['id' => 'batches', 'label' => 'Batches', 'route' => 'batches.index', 'match' => 'batches.*', 'icon' => 'batch'],
            ['id' => 'cutting', 'label' => 'Cheese Cutting', 'route' => 'cheese-cutting.index', 'match' => 'cheese-cutting.*', 'icon' => 'cut'],
            ['id' => 'stock', 'label' => 'Stock', 'route' => 'stock.index', 'match' => 'stock.*', 'icon' => 'stock'],
            ['id' => 'products', 'label' => 'Products', 'route' => 'products.index', 'match' => 'products.*', 'icon' => 'products'],
        ]];
    }

    if ($canSeeUsers) {
        $groups[] = ['label' => 'Admin', 'items' => [
            ['id' => 'users', 'label' => 'Users', 'route' => 'users.index', 'match' => 'users.*', 'icon' => 'users'],
        ]];
    }

    $icons = [
        'dashboard' => ['M3 12l9-9 9 9', 'M5 10v10h14V10'],
        'products'  => ['M3 7l9-4 9 4-9 4-9-4z', 'M3 7v10l9 4 9-4V7', 'M12 11v10'],
        'batch'     => ['M6 2h9l3 3v17H6z', 'M9 7h6M9 11h6M9 15h4'],
        'cut'       => ['M6 6l12 12M18 6L6 18', 'M8 8a2 2 0 1 1-4 0 2 2 0 0 1 4 0zM20 8a2 2 0 1 1-4 0 2 2 0 0 1 4 0z'],
        'stock'     => ['M3 9l9-6 9 6v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9z', 'M9 21V12h6v9'],
        'customer'  => ['M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', 'M23 21v-2a4 4 0 0 0-3-3.87', 'M16 3.13a4 4 0 0 1 0 7.75'],
        'order'     => ['M3 3h2l2 13h13l2-8H7', 'M10 21a1 1 0 1 1-2 0 1 1 0 0 1 2 0zM20 21a1 1 0 1 1-2 0 1 1 0 0 1 2 0z'],
        'alloc'     => ['M3 3h18v18H3z', 'M3 9h18M9 3v18'],
        'online'    => ['M12 20h.01', 'M2 8.82a15 15 0 0 1 20 0', 'M5 12.859a10 10 0 0 1 14 0', 'M8.5 16.429a5 5 0 0 1 7 0'],
        'users'     => ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z'],
        'search'    => ['M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35'],
        'chevron'   => ['M6 9l6 6 6-6'],
    ];

    $initials = collect(preg_split('/\s+/', trim($me->name)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('') ?: mb_strtoupper(mb_substr($me->username ?? 'U', 0, 2));
@endphp

<aside
    class="flex flex-col flex-shrink-0 h-screen md:sticky md:top-0"
    style="width: 232px; background: var(--sidebar); border-right: 1px solid var(--line);"
>
    <!-- Brand + mobile close -->
    <div class="flex items-center gap-2.5 px-5 pt-[18px] pb-[14px]">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
            <div
                class="grid place-items-center"
                style="width: 28px; height: 28px; border-radius: 7px; background: var(--ink); color: var(--bg); font-family: 'Fraunces', serif; font-weight: 600; font-size: 17px;"
            >M</div>
            <div class="text-[14px] font-semibold" style="letter-spacing: -0.1px;">
                Mossfield<span class="font-normal" style="color: var(--muted);"> Dairy</span>
            </div>
        </a>
        <button
            type="button"
            class="md:hidden ml-auto p-1 rounded"
            style="color: var(--muted);"
            @click="open = false"
        >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 6L6 18" /><path d="M6 6l12 12" />
            </svg>
        </button>
    </div>

    <!-- Search stub -->
    <div class="px-3 pt-1 pb-2.5">
        <div
            class="flex items-center gap-2 px-2.5 py-[7px] rounded-[7px] text-[13px]"
            style="background: var(--panel); border: 1px solid var(--line); color: var(--muted);"
        >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                @foreach ($icons['search'] as $d)<path d="{{ $d }}" />@endforeach
            </svg>
            <span>Search</span>
            <span class="ml-auto text-[11px] font-mono" style="color: var(--faint);">⌘K</span>
        </div>
    </div>

    <!-- Nav groups -->
    <nav class="flex-1 overflow-auto px-3 py-1">
        @foreach ($groups as $gi => $group)
            <div class="{{ $gi === 0 ? '' : 'mt-[18px]' }}">
                @if ($group['label'])
                    <div
                        class="px-2.5 pt-1 pb-1.5 text-[10.5px] font-semibold uppercase"
                        style="letter-spacing: 0.8px; color: var(--faint);"
                    >{{ $group['label'] }}</div>
                @endif
                @foreach ($group['items'] as $item)
                    @php $isActive = request()->routeIs($item['match']); @endphp
                    <a
                        href="{{ route($item['route']) }}"
                        class="flex items-center gap-2.5 px-2.5 py-[7px] rounded-md mb-px text-[13.5px] transition-colors"
                        @style([
                            'color: '.($isActive ? 'var(--ink)' : 'var(--ink-2)'),
                            'font-weight: '.($isActive ? 600 : 500),
                            'background: '.($isActive ? 'var(--panel)' : 'transparent'),
                            'box-shadow: '.($isActive ? '0 1px 2px rgba(0,0,0,0.04), inset 0 0 0 1px var(--line)' : 'none'),
                        ])
                    >
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            @foreach ($icons[$item['icon']] as $d)<path d="{{ $d }}" />@endforeach
                        </svg>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>

    <!-- User block -->
    <div class="border-t flex items-center gap-2.5 px-3 py-3" style="border-color: var(--line);">
        <x-dropdown align="top-start" width="48" class="w-full">
            <x-slot name="trigger">
                <button type="button" class="flex items-center gap-2.5 w-full text-left cursor-pointer rounded-md px-1 py-1 hover:bg-white/60 focus:outline-none focus:bg-white/60 transition-colors">
                    <div
                        class="grid place-items-center font-semibold text-[12px]"
                        style="width: 28px; height: 28px; border-radius: 14px; background: var(--accent); color: #fff;"
                    >{{ $initials }}</div>
                    <div class="flex-1 min-w-0">
                        <div class="text-[13px] font-medium truncate" style="color: var(--ink);">{{ $me->name }}</div>
                        <div class="text-[11px]" style="color: var(--muted);">{{ $me->role->label() }}</div>
                    </div>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="color: var(--muted);">
                        <path d="{{ $icons['chevron'][0] }}" />
                    </svg>
                </button>
            </x-slot>
            <x-slot name="content">
                <div class="px-4 pt-2 pb-2">
                    <div class="text-[12px]" style="color: var(--muted);">{{ $me->email ?: '@'.$me->username }}</div>
                </div>
                <x-dropdown-link :href="route('profile.edit')">
                    {{ __('Profile') }}
                </x-dropdown-link>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-dropdown-link :href="route('logout')"
                            onclick="event.preventDefault(); this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-dropdown-link>
                </form>
            </x-slot>
        </x-dropdown>
    </div>
</aside>
