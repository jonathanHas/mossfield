<x-app-layout>
    <x-slot name="header">Chilled Runs</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Chilled deliveries</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                    Run sheet for the week &mdash; milk, yoghurt &amp; cheese by stop. Pick a day to see its load.
                </div>
            </div>
            @if ($activeRun)
                <div class="flex items-center gap-3">
                    @if ($sheet['pendingCount'] > 0)
                        @can('create', App\Models\Order::class)
                            <form method="POST" action="{{ route('chilled-runs.confirm-all') }}"
                                  onsubmit="return confirm('Confirm {{ $sheet['pendingCount'] }} pending {{ Str::plural('order', $sheet['pendingCount']) }} on this run? They will appear on the picking queue.');">
                                @csrf
                                <input type="hidden" name="run" value="{{ $activeRun->id }}">
                                <input type="hidden" name="date" value="{{ request('date') }}">
                                <button type="submit" class="mf-btn-primary">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M20 6L9 17l-5-5" />
                                    </svg>
                                    Confirm all ({{ $sheet['pendingCount'] }})
                                </button>
                            </form>
                        @endcan
                    @endif
                    <div class="flex items-center gap-2 font-mono text-[12px]" style="color: var(--muted);">
                        <a href="{{ route('chilled-runs.index', ['run' => $activeRun->id, 'date' => $weekAnchor->subWeek()->toDateString()]) }}"
                           class="mf-btn-ghost" title="Previous week">&larr;</a>
                        <span>{{ $sheet['runDate']->format('d/m/Y') }}</span>
                        <a href="{{ route('chilled-runs.index', ['run' => $activeRun->id, 'date' => $weekAnchor->addWeek()->toDateString()]) }}"
                           class="mf-btn-ghost" title="Next week">&rarr;</a>
                    </div>
                </div>
            @endif
        </div>

        @if (session('success'))
            <div class="mf-flash mf-flash-success mb-4">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mf-flash mf-flash-error mb-4">{{ session('error') }}</div>
        @endif

        @if ($runs->isEmpty())
            @include('chilled-runs.partials._empty')
        @else
            @include('chilled-runs.partials._day-tabs')

            @if ($activeRun->capacity_note)
                <div class="mf-flash mf-flash-warn mb-4">
                    <div><b>Capacity.</b> {{ $activeRun->capacity_note }}</div>
                </div>
            @endif

            @include('chilled-runs.partials._summary-strip')
            @include('chilled-runs.partials._run-table')
        @endif
    </div>
</x-app-layout>
