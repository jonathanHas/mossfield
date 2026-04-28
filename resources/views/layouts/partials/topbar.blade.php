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
        <button type="button" class="mf-btn-ghost p-1.5" aria-label="Notifications">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
                <path d="M13.73 21a2 2 0 0 1-3.46 0" />
            </svg>
        </button>
        <div style="width: 1px; height: 18px; background: var(--line);"></div>
        <span class="hidden sm:inline text-[12px] font-mono" style="color: var(--muted);">
            {{ now()->format('D, j M · H:i') }}
        </span>
    </div>
</div>
