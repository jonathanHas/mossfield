<x-app-layout>
    <x-slot name="header">Delivery Runs</x-slot>

    <div class="px-6 py-5" x-data="{ showCharges: {{ request()->boolean('charges') ? 'true' : 'false' }} }">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Delivery runs</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                    Weekly chilled routes &mdash; assign customers as stops and set their drop order.
                    The <a href="{{ route('chilled-runs.index') }}" class="mf-link">chilled run sheet</a> reads from these.
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="mf-btn-ghost text-[12px]" @click="showCharges = !showCharges"
                        x-text="showCharges ? 'Hide charge settings' : 'Show charge settings'"></button>
                @can('create', App\Models\DeliveryRun::class)
                    <a href="{{ route('delivery-runs.create') }}" class="mf-btn-primary">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                        New run
                    </a>
                @endcan
            </div>
        </div>

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
                <div class="mf-panel scroll-mt-20" id="run-{{ $run->id }}">
                    <div class="mf-panel-header">
                        <span class="text-[13px] font-semibold">{{ $run->day_label }} &middot; {{ $run->name }}</span>
                        @unless ($run->is_active)
                            <span class="mf-tag mf-tag-neutral">Inactive</span>
                        @endunless
                        <span class="mf-eyebrow">{{ $run->driver ? 'Driver: '.$run->driver : '' }}</span>
                        @if ($run->delivery_charge > 0)
                            <span class="mf-tag mf-tag-neutral" x-show="showCharges" x-cloak>Charge €{{ number_format($run->delivery_charge, 2) }}</span>
                        @endif
                        <div class="ml-auto flex items-center gap-3">
                            <a href="{{ route('delivery-runs.edit', $run) }}" class="mf-link text-[12px]">Edit</a>
                            <form method="POST" action="{{ route('delivery-runs.destroy', $run) }}"
                                  data-confirm="Delete this run? Its customers will be unassigned (not deleted)." data-confirm-label="Delete" data-confirm-danger>
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
                            {{-- Position boxes post to the run's one reorder form (form=),
                                 since each row already holds its own small forms. --}}
                            <form id="reorder-{{ $run->id }}" method="POST" action="{{ route('delivery-runs.reorder', $run) }}">
                                @csrf
                            </form>
                            <ol class="space-y-1">
                                @foreach ($stops as $index => $stop)
                                    <li id="stop-{{ $stop->id }}" class="flex items-center gap-2 text-[13px] rounded px-2 py-1.5 scroll-mt-20" style="background: var(--bg);">
                                        <input type="number" name="positions[{{ $stop->id }}]" form="reorder-{{ $run->id }}"
                                               value="{{ $index + 1 }}" min="1" max="{{ $stops->count() }}" required
                                               aria-label="Position of {{ $stop->name }}"
                                               class="mf-input font-mono text-[11px] text-right" style="width: 46px; padding: 2px 4px;">
                                        <span class="font-medium flex-1">{{ $stop->name }}</span>

                                        {{-- Delivery charge — hidden until "Show charge settings". A
                                             percentage customer's charge comes from their own rate,
                                             not the run's, so there's nothing to toggle (F-18). --}}
                                        @if ($stop->delivery_charge_percent !== null)
                                            <span class="mf-tag mf-tag-neutral text-[11px]" x-show="showCharges" x-cloak
                                                  title="Set on the customer's page">{{ rtrim(rtrim(number_format((float) $stop->delivery_charge_percent, 2), '0'), '.') }} % of order value</span>
                                        @else
                                            <form method="POST" action="{{ route('delivery-runs.toggle-charge', $stop) }}" x-show="showCharges" x-cloak>
                                                @csrf
                                                <button type="submit" class="mf-btn-ghost text-[11px]" style="padding: 2px 6px;"
                                                        title="Toggle the run's delivery charge for this stop">
                                                    <span style="color: {{ $stop->apply_delivery_charge ? 'var(--accent)' : 'var(--muted)' }};">
                                                        € charge: {{ $stop->apply_delivery_charge ? 'on' : 'off' }}
                                                    </span>
                                                </button>
                                            </form>
                                        @endif

                                        {{-- ↑ / ↓: swap with the neighbour --}}
                                        @foreach (['up' => ['&uarr;', 'Move up', $index > 0], 'down' => ['&darr;', 'Move down', $index < $stops->count() - 1]] as $direction => [$arrow, $title, $show])
                                            @if ($show)
                                                <form method="POST" action="{{ route('delivery-runs.reorder', $run) }}">
                                                    @csrf
                                                    <input type="hidden" name="move[customer_id]" value="{{ $stop->id }}">
                                                    <input type="hidden" name="move[direction]" value="{{ $direction }}">
                                                    <button type="submit" class="mf-btn-ghost" style="padding: 2px 6px;" title="{{ $title }}">{!! $arrow !!}</button>
                                                </form>
                                            @endif
                                        @endforeach

                                        <form method="POST" action="{{ route('delivery-runs.unassign', $stop) }}"
                                              data-confirm="Remove {{ $stop->name }} from this run?" data-confirm-label="Remove" data-confirm-danger>
                                            @csrf
                                            <button type="submit" class="mf-btn-ghost" style="padding: 2px 6px; color: var(--danger);" title="Remove from run">&times;</button>
                                        </form>
                                    </li>
                                @endforeach
                            </ol>
                            @if ($stops->count() > 1)
                                <div class="flex justify-end mt-2">
                                    <button type="submit" form="reorder-{{ $run->id }}" class="mf-btn-secondary text-[12px]">Save order</button>
                                </div>
                            @endif
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
