<x-app-layout>
    <x-slot name="header">Delivery Runs</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Delivery runs</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                    Weekly chilled routes &mdash; assign customers as stops and set their drop order.
                    The <a href="{{ route('chilled-runs.index') }}" class="mf-link">chilled run sheet</a> reads from these.
                </div>
            </div>
            @can('create', App\Models\DeliveryRun::class)
                <a href="{{ route('delivery-runs.create') }}" class="mf-btn-primary">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 5v14M5 12h14" />
                    </svg>
                    New run
                </a>
            @endcan
        </div>

        @if (session('success'))
            <div class="mf-flash mf-flash-success mb-4">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mf-flash mf-flash-error mb-4">{{ session('error') }}</div>
        @endif

        @if ($runs->isEmpty())
            <div class="mf-panel">
                <div class="p-10 text-center">
                    <div class="text-[15px] font-medium mb-1">No delivery runs yet</div>
                    <div class="text-[13px]" style="color: var(--muted);">Create your first run to start building the chilled run sheet.</div>
                </div>
            </div>
        @endif

        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($runs as $run)
                <div class="mf-panel">
                    <div class="mf-panel-header">
                        <span class="text-[13px] font-semibold">{{ $run->day_label }} &middot; {{ $run->name }}</span>
                        @unless ($run->is_active)
                            <span class="mf-tag mf-tag-neutral">Inactive</span>
                        @endunless
                        <span class="mf-eyebrow">{{ $run->driver ? 'Driver: '.$run->driver : '' }}</span>
                        <div class="ml-auto flex items-center gap-3">
                            <a href="{{ route('delivery-runs.edit', $run) }}" class="mf-link text-[12px]">Edit</a>
                            <form method="POST" action="{{ route('delivery-runs.destroy', $run) }}"
                                  onsubmit="return confirm('Delete this run? Its customers will be unassigned (not deleted).');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="mf-link text-[12px]" style="color: var(--danger);">Delete</button>
                            </form>
                        </div>
                    </div>

                    @if ($run->capacity_note)
                        <div class="px-4 pt-3 text-[12px]" style="color: var(--warn-ink);">{{ $run->capacity_note }}</div>
                    @endif

                    <div class="p-4">
                        @php $stops = $run->customers; @endphp
                        @if ($stops->isEmpty())
                            <div class="text-[13px]" style="color: var(--muted);">No stops yet.</div>
                        @else
                            <ol class="space-y-1">
                                @foreach ($stops as $index => $stop)
                                    <li class="flex items-center gap-2 text-[13px] rounded px-2 py-1.5" style="background: var(--bg);">
                                        <span class="font-mono text-[11px] w-5 text-right" style="color: var(--faint);">{{ $index + 1 }}</span>
                                        <span class="font-medium flex-1">{{ $stop->name }}</span>

                                        {{-- Move up: swap with previous stop --}}
                                        @if ($index > 0)
                                            <form method="POST" action="{{ route('delivery-runs.reorder', $run) }}">
                                                @csrf
                                                @foreach ($stops as $j => $other)
                                                    <input type="hidden" name="positions[]"
                                                           value="{{ $j === $index ? $stops[$index - 1]->id : ($j === $index - 1 ? $stop->id : $other->id) }}">
                                                @endforeach
                                                <button type="submit" class="mf-btn-ghost" style="padding: 2px 6px;" title="Move up">&uarr;</button>
                                            </form>
                                        @endif

                                        {{-- Move down: swap with next stop --}}
                                        @if ($index < $stops->count() - 1)
                                            <form method="POST" action="{{ route('delivery-runs.reorder', $run) }}">
                                                @csrf
                                                @foreach ($stops as $j => $other)
                                                    <input type="hidden" name="positions[]"
                                                           value="{{ $j === $index ? $stops[$index + 1]->id : ($j === $index + 1 ? $stop->id : $other->id) }}">
                                                @endforeach
                                                <button type="submit" class="mf-btn-ghost" style="padding: 2px 6px;" title="Move down">&darr;</button>
                                            </form>
                                        @endif

                                        <form method="POST" action="{{ route('delivery-runs.unassign', $stop) }}"
                                              onsubmit="return confirm('Remove {{ $stop->name }} from this run?');">
                                            @csrf
                                            <button type="submit" class="mf-btn-ghost" style="padding: 2px 6px; color: var(--danger);" title="Remove from run">&times;</button>
                                        </form>
                                    </li>
                                @endforeach
                            </ol>
                        @endif

                        @if ($unassignedCustomers->isNotEmpty())
                            <form method="POST" action="{{ route('delivery-runs.assign', $run) }}" class="flex items-end gap-2 mt-3">
                                @csrf
                                <div class="flex-1">
                                    <label for="customer_id_{{ $run->id }}" class="mf-label">Add stop</label>
                                    <select name="customer_id" id="customer_id_{{ $run->id }}" class="mf-select" required>
                                        <option value="">Choose a customer…</option>
                                        @foreach ($unassignedCustomers as $customer)
                                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="submit" class="mf-btn-secondary">Add</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-app-layout>
