<div
    class="flex items-center gap-4 flex-shrink-0 px-6"
    style="height: 56px; border-bottom: 1px solid var(--line); background: var(--panel);"
>
    <!-- Mobile hamburger -->
    <button
        type="button"
        class="md:hidden -ml-2 p-2 rounded"
        style="color: var(--ink-2);"
        @click="open = true"
    >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>

    <!-- Desktop sidebar collapse toggle -->
    <button
        type="button"
        class="hidden md:inline-flex -ml-2 p-2 rounded"
        style="color: var(--ink-2);"
        @click="collapsed = !collapsed; localStorage.setItem('sidebar-collapsed', collapsed ? '1' : '0')"
        :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        title="Toggle sidebar"
    >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>

    <!-- Breadcrumb / page title from the $header slot -->
    <div class="flex-1 min-w-0 flex items-center gap-2.5">
        @isset($header)
            <div class="text-[13.5px] font-semibold truncate" style="color: var(--ink);">
                {{ $header }}
            </div>
        @endisset
    </div>

    <!-- Right rail -->
    <div class="flex items-center gap-2" style="color: var(--muted);">
        <span class="hidden sm:inline text-[12px] font-mono" style="color: var(--muted);">
            {{ now()->format('D, j M · H:i') }}
        </span>
    </div>
</div>
