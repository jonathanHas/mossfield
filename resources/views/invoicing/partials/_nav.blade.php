{{-- Sub-navigation shared by every finance screen, so the queues stay one
     click apart no matter where you are. --}}
@php
    // The same list the sidebar's Finance group renders (E29).
    $tabs = \App\View\FinanceNav::items();
@endphp
<div class="flex flex-wrap gap-1.5 mb-4">
    @foreach($tabs as $tab)
        @php $active = request()->routeIs($tab['match']); @endphp
        <a href="{{ route($tab['route']) }}"
           class="text-[12.5px] px-3 py-1.5 rounded-md {{ $active ? 'font-semibold' : '' }}"
           style="{{ $active
                ? 'background: var(--ink); color: var(--bg);'
                : 'background: var(--surface-2, #f4f5f2); color: var(--muted); border: 1px solid var(--line-2);' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
