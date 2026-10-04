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
                                  data-confirm="Confirm {{ $sheet['pendingCount'] }} pending {{ Str::plural('order', $sheet['pendingCount']) }} on this run? They will appear on the picking queue." data-confirm-label="Confirm">
                                @csrf
                                <input type="hidden" name="run" value="{{ $activeRun->id }}">
                                <input type="hidden" name="date" value="{{ request('date') }}">
                                <button type="submit" class="mf-btn-primary" data-confirm-unsaved>
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M20 6L9 17l-5-5" />
                                    </svg>
                                    Confirm all ({{ $sheet['pendingCount'] }})
                                </button>
                            </form>
                        @endcan
                    @endif
                    @if ($sheet['allocatableCount'] > 0)
                        @can('create', App\Models\Order::class)
                            <form method="POST" action="{{ route('chilled-runs.allocate-all') }}"
                                  data-confirm="Reserve available stock (oldest batches first) for {{ $sheet['allocatableCount'] }} {{ Str::plural('order', $sheet['allocatableCount']) }} on this run?" data-confirm-label="Allocate">
                                @csrf
                                <input type="hidden" name="run" value="{{ $activeRun->id }}">
                                <input type="hidden" name="date" value="{{ request('date') }}">
                                <button type="submit" class="mf-btn-secondary" data-confirm-unsaved>
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" />
                                    </svg>
                                    Allocate all ({{ $sheet['allocatableCount'] }})
                                </button>
                            </form>
                        @endcan
                    @endif
                    @if ($sheet['dispatchableCount'] > 0)
                        @can('create', App\Models\Order::class)
                            <form method="POST" action="{{ route('chilled-runs.dispatch-all') }}"
                                  data-confirm="Dispatch {{ $sheet['dispatchableCount'] }} loaded {{ Str::plural('order', $sheet['dispatchableCount']) }}? Figures are frozen: invoices are issued for per-order customers and a dispatch confirmation is emailed to each shop. Invoices are sent from Finance." data-confirm-label="Dispatch">
                                @csrf
                                <input type="hidden" name="run" value="{{ $activeRun->id }}">
                                <input type="hidden" name="date" value="{{ request('date') }}">
                                <button type="submit" class="mf-btn-primary" data-confirm-unsaved>
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M1 3h15v13H1z" /><path d="M16 8h4l3 3v5h-7" /><path d="M5.5 18.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5zM18.5 18.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z" />
                                    </svg>
                                    Dispatch run ({{ $sheet['dispatchableCount'] }})
                                </button>
                            </form>
                        @endcan
                    @endif
                    <div class="flex items-center gap-2 font-mono text-[12px]" style="color: var(--muted);">
                        <a href="{{ route('chilled-runs.index', ['run' => $activeRun->id, 'date' => $weekAnchor->subWeek()->toDateString()]) }}"
                           data-confirm-unsaved class="mf-btn-ghost" title="Previous week">&larr;</a>
                        <span>{{ $sheet['runDate']->format('d/m/Y') }}</span>
                        <a href="{{ route('chilled-runs.index', ['run' => $activeRun->id, 'date' => $weekAnchor->addWeek()->toDateString()]) }}"
                           data-confirm-unsaved class="mf-btn-ghost" title="Next week">&rarr;</a>
                    </div>
                </div>
            @endif
        </div>

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
