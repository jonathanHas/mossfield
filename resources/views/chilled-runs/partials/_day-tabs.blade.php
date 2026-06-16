{{-- Day selector — one tab per active run; links preserve the week anchor. --}}
<div class="mf-daytabs mb-4">
    @foreach ($runs as $run)
        <a href="{{ route('chilled-runs.index', array_filter(['run' => $run->id, 'date' => request('date')])) }}"
           class="mf-daytab{{ $run->id === $activeRun->id ? ' is-active' : '' }}">
            <span class="d">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                    <path d="M12 2v20M2 12h20M5.6 5.6l12.8 12.8M18.4 5.6L5.6 18.4" />
                </svg>
                {{ $run->day_label }} &middot; {{ $run->name }}
            </span>
            <span class="m">{{ $run->stops_count }} {{ Str::plural('stop', $run->stops_count) }}{{ $run->driver ? ' · '.$run->driver : '' }}</span>
        </a>
    @endforeach
</div>
