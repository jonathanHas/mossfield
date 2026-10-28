@php
    $milkCols = $sheet['milkCols'];
    $yogCols = $sheet['yogCols'];
    $cheeseCols = $sheet['cheeseCols'];
    $qtyColCount = $milkCols->count() + $yogCols->count() + $cheeseCols->count();
    $editId = $sheet['editCustomerId'];
    $canEnter = auth()->user()->can('create', App\Models\Order::class);
    $sheetParams = array_filter(['run' => $activeRun->id, 'date' => request('date')]);

    // variant id => short label, for the editor's added-line rows.
    $variantLabels = collect();
    foreach ($sheet['addLineVariants'] as $productName => $variants) {
        foreach ($variants as $v) {
            $variantLabels[$v->id] = $productName.' — '.$v->name;
        }
    }
    foreach ($cheeseCols as $v) {
        $variantLabels[$v->id] = $v->product->name.' — '.$v->name;
    }

    // Short variety label for cheese column headers — two cheese products can
    // share a variant size (e.g. both have a "wheel"), so the size alone is
    // ambiguous. Strip whole-word farm/category boilerplate from the product
    // name (word boundaries so "Farmhouse" survives the "Farm" strip).
    $cheeseShortLabel = fn ($variant) => trim(preg_replace(
        ['/\b(Mossfield|Organic|Farm|Cheese)\b/i', '/\s{2,}/'],
        ['', ' '],
        $variant->product->name
    )) ?: $variant->product->name;
@endphp

{{-- Run sheet table. When the sheet fits its panel, neither the panel
     (mf-panel clips with overflow: hidden) nor the wrapper is a scroll/clip
     container, so the sticky <thead> works. Whether it fits depends on the
     column count, not on a fixed breakpoint. Each quantity column is 60 px;
     11 of them made the sheet 983 px (measured cycle 6) in a ~985 px panel at
     xl (1280), so the non-quantity columns take ~323 px. At 2xl (1536) the
     panel is ~256 px wider (~1241 px): (1241 − 323) / 60 ≈ 15 columns. More
     than that — or a row being edited (its inputs need ~35 px more than the
     columns) — and the wrapper scrolls sideways and the header scrolls away. --}}
@php
    $stickyHead = match (true) {
        (bool) $editId => '',
        $qtyColCount <= 11 => 'xl:overflow-visible',
        $qtyColCount <= 15 => '2xl:overflow-visible',
        default => '',
    };
@endphp
<div class="mf-panel {{ $stickyHead }}">
    <div class="mf-panel-header">
        <span class="text-[13px] font-semibold">{{ $activeRun->day_label }} &middot; {{ $activeRun->name }} run</span>
        <span class="mf-eyebrow">{{ $activeRun->driver ? 'Driver: '.$activeRun->driver : '' }}</span>
        @if ($activeRun->capacity_note)<span class="mf-tag mf-tag-neutral">Capacity: {{ $activeRun->capacity_note }}</span>@endif
        <div class="ml-auto font-mono text-[12px]" style="color: var(--muted);">{{ $sheet['runDate']->format('D d/m/Y') }}</div>
    </div>

    @if ($sheet['rows']->isEmpty())
        <div class="p-8 text-center text-[13px]" style="color: var(--muted);">
            No customers assigned to this run yet.
            @can('update', $activeRun)
                <a href="{{ route('delivery-runs.index') }}" class="mf-link">Manage stops</a>
            @endcan
        </div>
    @else
        <div class="overflow-x-auto {{ $stickyHead }}">
            <table class="mf-run">
                <colgroup>
                    <col style="width: 44px;">
                    <col>
                    @for ($i = 0; $i < $qtyColCount; $i++)
                        <col style="width: 60px;">
                    @endfor
                    <col style="width: 300px;">
                </colgroup>
                <thead>
                    <tr>
                        <th class="grp-th"></th>
                        <th class="grp-th"></th>
                        @if ($milkCols->isNotEmpty())
                            <th class="grp-th grp-milk col-milk" colspan="{{ $milkCols->count() }}">Milk</th>
                        @endif
                        @if ($yogCols->isNotEmpty())
                            <th class="grp-th grp-yog col-yog" colspan="{{ $yogCols->count() }}">Yoghurt</th>
                        @endif
                        @if ($cheeseCols->isNotEmpty())
                            <th class="grp-th grp-cheese col-cheese" colspan="{{ $cheeseCols->count() }}">Cheese</th>
                        @endif
                        <th class="grp-th"></th>
                    </tr>
                    <tr>
                        <th class="sub-th"></th>
                        <th class="sub-th l">Customer</th>
                        @foreach ($milkCols as $variant)
                            <th class="sub-th col-milk">{{ $variant->size ?: $variant->name }}</th>
                        @endforeach
                        @foreach ($yogCols as $variant)
                            <th class="sub-th col-yog">{{ $variant->size ?: $variant->name }}</th>
                        @endforeach
                        @foreach ($cheeseCols as $variant)
                            <th class="sub-th col-cheese" title="{{ $variant->product->name }} — {{ $variant->name }}">
                                <span class="vrt">{{ $cheeseShortLabel($variant) }}</span>
                                {{ $variant->size ?: $variant->name }}
                            </th>
                        @endforeach
                        <th class="sub-th l">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sheet['rows'] as $row)
                        @php
                            $customer = $row['customer'];
                            $orders = $row['orders'];
                            $hasOrders = $orders->isNotEmpty();
                            $isLoaded = $hasOrders && $orders->every(fn ($o) => $o->loaded_at !== null);
                            $isEditing = $customer->id === $editId;
                        @endphp

                        @if ($isEditing)
                            {{-- ── Edit mode: quantity inputs + history recall + add-line ── --}}
                            <tr id="stop-{{ $customer->id }}" class="is-editing mf-landing"
                                x-data="stopEditor({
                                    current: {{ Js::from((object) $sheet['editQuantities']) }},
                                    history: {{ Js::from($sheet['history']) }},
                                    fixedIds: {{ Js::from($milkCols->pluck('id')->merge($yogCols->pluck('id'))->values()) }},
                                    cheeseColIds: {{ Js::from($cheeseCols->pluck('id')->values()) }},
                                    labels: {{ Js::from((object) $variantLabels->all()) }},
                                    customer: {{ Js::from($customer->name) }},
                                    reference: {{ Js::from($sheet['editCustomerReference']) }},
                                    notes: {{ Js::from($sheet['editNotes'] ?? '') }},
                                    requiresRef: {{ $customer->requires_reference ? 'true' : 'false' }},
                                })">
                                <td class="pad"></td>
                                <td class="cust">
                                    <div class="nm">{{ $customer->name }}</div>
                                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                        <button type="button" class="mf-btn-secondary" style="padding: 3px 8px; font-size: 11.5px;"
                                                @click="repeatLast()" x-show="history.length" x-cloak>
                                            Repeat last order
                                        </button>
                                        <template x-if="history.length > 1">
                                            <span class="inline-flex items-center gap-1">
                                                <button type="button" class="mf-btn-ghost" style="padding: 2px 6px;" @click="older()" :disabled="hIndex >= history.length - 1">&larr;</button>
                                                <button type="button" class="mf-btn-ghost" style="padding: 2px 6px;" @click="newer()" :disabled="hIndex <= 0">&rarr;</button>
                                            </span>
                                        </template>
                                        <span class="font-mono" style="font-size: 10.5px; color: var(--muted);"
                                              x-show="hIndex >= 0" x-text="hIndex >= 0 ? 'from ' + history[hIndex].label : ''" x-cloak></span>
                                    </div>
                                    <div class="mt-2 flex items-center gap-1.5">
                                        <button type="submit" form="stop-form-{{ $customer->id }}" class="mf-btn-primary" style="padding: 4px 12px;">Save</button>
                                        <a href="{{ route('chilled-runs.index', $sheetParams) }}#stop-{{ $customer->id }}" class="mf-btn-ghost" style="padding: 4px 8px;" @click="window.__stopDirty = false">Cancel</a>
                                        <button type="button" class="mf-btn-ghost whitespace-nowrap" style="padding: 4px 8px;" @click="showExtras = !showExtras" x-show="!showExtras" x-cloak title="Add a customer reference">+ Ref</button>
                                    </div>
                                </td>
                                @foreach ($milkCols->concat($yogCols)->concat($cheeseCols) as $variant)
                                    @php $tint = $variant->product->type === 'milk' ? 'col-milk' : ($variant->product->type === 'yoghurt' ? 'col-yog' : 'col-cheese'); @endphp
                                    <td class="qty {{ $tint }}">
                                        <input type="number" min="0" max="65000" class="mf-run-qin"
                                               form="stop-form-{{ $customer->id }}"
                                               name="qty[{{ $variant->id }}]"
                                               x-model.number="qty[{{ $variant->id }}]"
                                               aria-label="{{ $variant->product->name }} {{ $variant->name }}">
                                    </td>
                                @endforeach
                                <td class="extras">
                                    {{-- Customer reference (optional; auto-shown when the customer requires one). --}}
                                    <div x-show="showExtras" x-cloak class="mb-2">
                                        <input type="text" maxlength="255" class="mf-run-qin" style="width: 150px;"
                                               form="stop-form-{{ $customer->id }}"
                                               name="customer_reference"
                                               x-model="reference"
                                               placeholder="Customer ref"
                                               aria-label="Customer reference">
                                    </div>
                                    {{-- Added lines (cheese etc.) — become columns after save. --}}
                                    <template x-for="line in addedLines" :key="line.variant_id">
                                        <div class="flex items-center gap-1.5 mb-1.5">
                                            <input type="number" min="0" max="65000" class="mf-run-qin"
                                                   form="stop-form-{{ $customer->id }}"
                                                   :name="'qty[' + line.variant_id + ']'"
                                                   x-model.number="line.qty">
                                            <span class="text-[12px]" x-text="labels[line.variant_id] ?? ('Variant #' + line.variant_id)"></span>
                                            <button type="button" class="mf-btn-ghost" style="padding: 0 5px; color: var(--danger);"
                                                    @click="removeAdded(line.variant_id)" title="Remove line">&times;</button>
                                        </div>
                                    </template>
                                    @if (count($sheet['addLineVariants']))
                                        <select class="mf-select" style="font-size: 12px; padding: 4px 28px 4px 8px; width: auto; display: inline-block;"
                                                @change="addLine($event.target.value); $event.target.value = ''">
                                            <option value="">Add line…</option>
                                            @foreach ($sheet['addLineVariants'] as $productName => $variants)
                                                <optgroup label="{{ $productName }}">
                                                    @foreach ($variants as $variant)
                                                        <option value="{{ $variant->id }}">{{ $variant->picker_label }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @endforeach
                                        </select>
                                    @endif
                                    {{-- Order notes (F-28) — saved with the row. --}}
                                    <textarea rows="2" maxlength="2000" class="mf-input mt-1.5" style="width: 100%; min-width: 180px; font-size: 12px; padding: 4px 6px;"
                                              form="stop-form-{{ $customer->id }}"
                                              name="notes"
                                              x-model="notes"
                                              placeholder="Notes"
                                              aria-label="Order notes"></textarea>
                                </td>
                            </tr>
                        @else
                            {{-- ── Display mode ── --}}
                            <tr id="stop-{{ $customer->id }}" class="mf-landing {{ $isLoaded ? 'is-loaded' : '' }}">
                                <td class="pad">
                                    @if ($hasOrders)
                                        @can('load', $orders->first())
                                            {{-- A stop with several orders names each tick (F-27). --}}
                                            @php $multiOrder = $orders->count() > 1; @endphp
                                            @foreach ($orders as $order)
                                                <div class="{{ $multiOrder ? 'flex items-center gap-1 mb-1' : '' }}">
                                                @if ($order->isDispatchedOrLater())
                                                    {{-- Gone: the tick is history now, not a control. --}}
                                                    <span class="mf-run-check on" style="cursor: default;" title="Dispatched">
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M20 6L9 17l-5-5" />
                                                        </svg>
                                                    </span>
                                                @else
                                                    <form method="POST" action="{{ route('chilled-runs.toggle-loaded', $order) }}">
                                                        @csrf
                                                        <input type="hidden" name="run" value="{{ $activeRun->id }}">
                                                        <input type="hidden" name="date" value="{{ request('date') }}">
                                                        <button type="submit"
                                                                class="mf-run-check{{ $order->loaded_at ? ' on' : '' }}"
                                                                aria-label="{{ $multiOrder
                                                                    ? ($order->loaded_at ? $order->order_number.' loaded — tap to unmark' : 'Mark '.$order->order_number.' loaded')
                                                                    : ($order->loaded_at ? 'Loaded — tap to unmark' : 'Mark loaded') }}">
                                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                                                <path d="M20 6L9 17l-5-5" />
                                                            </svg>
                                                        </button>
                                                    </form>
                                                @endif
                                                @if ($multiOrder)
                                                    <span class="font-mono text-[10px]" style="color: var(--muted); white-space: nowrap;" data-tick-order>{{ $order->order_number }}</span>
                                                @endif
                                                </div>
                                            @endforeach
                                        @else
                                            <span class="mf-run-check{{ $isLoaded ? ' on' : '' }}" style="cursor: default;">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M20 6L9 17l-5-5" />
                                                </svg>
                                            </span>
                                        @endcan
                                    @endif
                                </td>
                                {{-- The town on the sheet, the full address on hover (F34). --}}
                                @php $fullAddress = collect([$customer->address, $customer->city, $customer->postal_code])->filter()->implode(', '); @endphp
                                <td class="cust" @if ($fullAddress !== '') title="{{ $fullAddress }}" @endif>
                                    <div class="nm">{{ $customer->name }}</div>
                                    @if (filled($customer->city))
                                        <div class="ad">{{ $customer->city }}</div>
                                    @endif
                                    @if ($hasOrders)
                                        @php $st = $row['stats']; @endphp
                                        <div class="so">
                                            @foreach ($orders as $order)
                                                @can('view', $order)
                                                    <a href="{{ route('orders.show', $order) }}" data-confirm-unsaved>{{ $order->order_number }}</a>
                                                @else
                                                    {{ $order->order_number }}
                                                @endcan
                                                @if (! $loop->last) &middot; @endif
                                            @endforeach
                                        </div>
                                        <div class="tags">
                                            @if ($orders->contains(fn ($o) => $o->status === 'pending'))
                                                <span class="mf-tag mf-tag-warn">Pending</span>
                                            @endif
                                            @if ($st['allocState'] === 'unallocated')
                                                <span class="mf-tag mf-tag-neutral">Not allocated</span>
                                            @elseif ($st['allocState'] === 'partial')
                                                <span class="mf-tag mf-tag-warn">{{ $st['allocated'] }}/{{ $st['ordered'] }} allocated</span>
                                            @elseif ($st['allocState'] === 'allocated' && $st['pickState'] === 'todo')
                                                <span class="mf-tag mf-tag-info">Allocated</span>
                                            @endif
                                            @if ($st['dispatchState'] === 'dispatched')
                                                <span class="mf-tag mf-tag-accent">Dispatched</span>
                                            @elseif ($st['pickState'] === 'ready')
                                                <span class="mf-tag mf-tag-accent">Ready</span>
                                            @elseif ($st['pickState'] === 'picking')
                                                <span class="mf-tag mf-tag-info">{{ $st['picked'] }}/{{ $st['ordered'] }} picked</span>
                                            @endif
                                        </div>
                                    @endif
                                    @if (! empty($sheet['references'][$customer->id]))
                                        <div class="so" style="color: var(--muted);">Ref: {{ $sheet['references'][$customer->id] }}</div>
                                    @endif
                                    @if ($canEnter)
                                        <div class="acts">
                                            @if ($row['editable'])
                                                <a class="mf-link text-[12px]" data-confirm-unsaved
                                                   href="{{ route('chilled-runs.index', array_merge($sheetParams, ['edit' => $customer->id])) }}#stop-{{ $customer->id }}">
                                                    {{ $hasOrders ? 'Edit' : 'Enter order' }}
                                                </a>
                                            @elseif ($orders->count() > 1)
                                                <span class="text-[12px]" style="color: var(--muted);">Multiple orders &mdash; open each to edit</span>
                                            @endif
                                            @if ($row['stats']['canAllocate'])
                                                <form method="POST" action="{{ route('chilled-runs.allocate-stop', $customer) }}" style="display: inline-block;">
                                                    @csrf
                                                    <input type="hidden" name="run" value="{{ $activeRun->id }}">
                                                    <input type="hidden" name="date" value="{{ request('date') }}">
                                                    <button type="submit" class="mf-btn-ghost text-[12px]" style="padding: 2px 8px;"
                                                            data-confirm-unsaved
                                                            title="Reserve available stock for this stop, oldest batches first">Allocate</button>
                                                </form>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                @foreach ($milkCols->concat($yogCols)->concat($cheeseCols) as $variant)
                                    @php
                                        $tint = $variant->product->type === 'milk' ? 'col-milk' : ($variant->product->type === 'yoghurt' ? 'col-yog' : 'col-cheese');
                                        $qty = $sheet['qtyMap'][$customer->id][$variant->id] ?? 0;
                                        // Per-cell allocation state: which products at this stop
                                        // are covered. Denominator is the raw cell qty so the
                                        // fraction always matches the number above it.
                                        $allocState = null;
                                        if ($qty > 0 && $hasOrders && $row['stats']['showCellAlloc']) {
                                            $a = $sheet['allocMap'][$customer->id][$variant->id] ?? ['allocated' => 0, 'picked' => 0, 'short' => 0];
                                            $allocState = $a['allocated'] >= $qty ? 'full'
                                                : ($a['short'] > 0 && $a['allocated'] + $a['short'] >= $qty ? 'short'
                                                : ($a['allocated'] > 0 ? 'partial' : 'none'));
                                        }
                                    @endphp
                                    <td class="qty {{ $qty === 0 ? 'zero' : '' }} {{ $tint }} {{ $allocState ? 'alloc-'.$allocState : '' }}">
                                        {{ $qty === 0 ? '·' : $qty }}
                                        @if ($allocState === 'full')
                                            <div class="alloc" title="All {{ $qty }} allocated">&check;</div>
                                        @elseif ($allocState === 'short')
                                            <div class="alloc" title="{{ $a['short'] }} short &mdash; no stock">{{ $a['allocated'] }}/{{ $qty }}</div>
                                        @elseif ($allocState)
                                            <div class="alloc" title="{{ $a['allocated'] }} of {{ $qty }} allocated">{{ $a['allocated'] }}/{{ $qty }}</div>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="extras">
                                    @if ($sheet['extras'][$customer->id] !== '')
                                        {{ $sheet['extras'][$customer->id] }}
                                    @else
                                        <span style="color: var(--faint);">&mdash;</span>
                                    @endif
                                    @unless ($hasOrders)
                                        <div style="margin-top: 6px;">
                                            <span class="mf-tag mf-tag-neutral">No order this week</span>
                                        </div>
                                    @endunless
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
                @if ($qtyColCount > 0)
                    <tfoot>
                        <tr>
                            <td class="lbl" colspan="2">Total units</td>
                            @foreach ($milkCols->concat($yogCols)->concat($cheeseCols) as $variant)
                                @php $tint = $variant->product->type === 'milk' ? 'col-milk' : ($variant->product->type === 'yoghurt' ? 'col-yog' : 'col-cheese'); @endphp
                                <td class="tot {{ $tint }}">{{ $sheet['totals'][$variant->id]['units'] ?: '—' }}</td>
                            @endforeach
                            <td></td>
                        </tr>
                        <tr class="sub-tot">
                            <td class="lbl" colspan="2">Blue crates</td>
                            @foreach ($milkCols->concat($yogCols)->concat($cheeseCols) as $variant)
                                @php $tint = $variant->product->type === 'milk' ? 'col-milk' : ($variant->product->type === 'yoghurt' ? 'col-yog' : 'col-cheese'); @endphp
                                <td class="tot {{ $tint }}">{{ $sheet['totals'][$variant->id]['crates'] ?: '—' }}</td>
                            @endforeach
                            <td class="lbl" style="padding-left: 12px;">{{ $sheet['cratesTotal'] }} crates total</td>
                        </tr>
                        <tr class="sub-tot">
                            <td class="lbl" colspan="2">Extra units outside crate</td>
                            @foreach ($milkCols->concat($yogCols)->concat($cheeseCols) as $variant)
                                @php $tint = $variant->product->type === 'milk' ? 'col-milk' : ($variant->product->type === 'yoghurt' ? 'col-yog' : 'col-cheese'); @endphp
                                <td class="tot {{ $tint }}">{{ $sheet['totals'][$variant->id]['extra'] ?: '—' }}</td>
                            @endforeach
                            <td></td>
                        </tr>
                        {{-- Free stock per column: what's genuinely left after every
                             reservation. Red where this run still can't be covered.
                             A real 0 where the column has orders; — only where it has none. --}}
                        @php $shortCols = collect($sheet['stock'])->filter(fn ($s) => $s['short'])->count(); @endphp
                        <tr class="sub-tot">
                            <td class="lbl" colspan="2">Stock available</td>
                            @foreach ($milkCols->concat($yogCols)->concat($cheeseCols) as $variant)
                                @php
                                    $tint = $variant->product->type === 'milk' ? 'col-milk' : ($variant->product->type === 'yoghurt' ? 'col-yog' : 'col-cheese');
                                    $s = $sheet['stock'][$variant->id] ?? ['free' => 0, 'outstanding' => 0, 'short' => false];
                                @endphp
                                <td class="tot {{ $tint }} {{ $s['short'] ? 'short' : '' }}"
                                    title="{{ $s['free'] }} free · {{ $s['outstanding'] }} still to allocate">{{ $s['free'] > 0 || ($sheet['totals'][$variant->id]['units'] ?? 0) > 0 ? $s['free'] : '—' }}</td>
                            @endforeach
                            <td class="lbl" style="padding-left: 12px;">
                                @if ($shortCols > 0)
                                    <span style="color: var(--danger);">{{ $shortCols }} {{ Str::plural('column', $shortCols) }} short</span>
                                @endif
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        @if ($editId)
            {{-- The row form: a <form> can't wrap a <tr>, so the editing row's
                 inputs associate with this element via the form= attribute. --}}
            <form id="stop-form-{{ $editId }}" method="POST"
                  action="{{ route('chilled-runs.save-stop', $editId) }}" style="display: none;">
                @csrf
                <input type="hidden" name="run" value="{{ $activeRun->id }}">
                <input type="hidden" name="date" value="{{ request('date') }}">
            </form>

            <script>
                // Row editor: quantity state + order-history prefill + added lines.
                function stopEditor(init) {
                    return {
                        qty: { ...init.current },
                        history: init.history,
                        labels: init.labels,
                        hIndex: -1,        // -1 = not previewing history
                        addedLines: [],    // cheese (non-column) lines: [{variant_id, qty}]
                        reference: init.reference ?? '',          // optional customer ref / PO number
                        notes: init.notes ?? '',                  // order notes
                        showExtras: !!(init.reference || init.requiresRef),  // auto-open for ref'd customers
                        baseline: '',      // snapshot of the as-loaded order, for dirty detection

                        init() {
                            window.__stopDirty = false;
                            window.__stopCustomer = init.customer;
                            this.baseline = this.serialize();
                            this.$watch('qty', () => this.sync());
                            this.$watch('addedLines', () => this.sync());
                            this.$watch('reference', () => this.sync());
                            this.$watch('notes', () => this.sync());
                            // Save clears the dirty flag so neither guard prompts on submit.
                            const form = document.getElementById('stop-form-{{ $editId }}');
                            if (form) form.addEventListener('submit', () => { window.__stopDirty = false; });
                            // Net for back button / refresh / tab close / sidebar nav.
                            window.addEventListener('beforeunload', (e) => {
                                if (window.__stopDirty) { e.preventDefault(); e.returnValue = ''; }
                            });
                        },
                        serialize() {
                            return JSON.stringify({ qty: this.qty, added: this.addedLines, reference: this.reference, notes: this.notes });
                        },
                        sync() {
                            window.__stopDirty = this.serialize() !== this.baseline;
                        },

                        repeatLast() {
                            if (this.history.length) this.applyHistory(0);
                        },
                        older() {
                            if (this.hIndex < this.history.length - 1) this.applyHistory(this.hIndex + 1);
                        },
                        newer() {
                            if (this.hIndex > 0) this.applyHistory(this.hIndex - 1);
                        },
                        applyHistory(i) {
                            this.hIndex = i;
                            const from = this.history[i].quantities;
                            this.qty = {};
                            init.fixedIds.forEach(id => { this.qty[id] = from[id] ?? 0; });
                            init.cheeseColIds.forEach(id => { this.qty[id] = from[id] ?? 0; });
                            this.addedLines = [];
                            Object.entries(from).forEach(([vid, n]) => {
                                vid = Number(vid);
                                if (!init.fixedIds.includes(vid) && !init.cheeseColIds.includes(vid)) {
                                    this.addedLines.push({ variant_id: vid, qty: n });
                                }
                            });
                        },
                        addLine(variantId) {
                            variantId = Number(variantId);
                            if (!variantId) return;
                            if (!this.addedLines.some(l => l.variant_id === variantId)) {
                                this.addedLines.push({ variant_id: variantId, qty: 1 });
                            }
                        },
                        removeAdded(variantId) {
                            this.addedLines = this.addedLines.filter(l => l.variant_id !== variantId);
                        },
                    };
                }

                // In-app guard: before leaving the edit row via a run-sheet link
                // (other rows' Edit/Enter order, day tabs, week nav) OR a form button
                // that navigates away (Confirm all, Allocate all, per-stop Allocate),
                // show a labelled modal ("Keep editing" / "Discard changes") instead of
                // the browser's generic OK/Cancel confirm.
                let __stopPendingAction = null;
                document.addEventListener('click', function (e) {
                    const el = e.target.closest('[data-confirm-unsaved]');
                    if (!el || !window.__stopDirty) return;
                    e.preventDefault();
                    __stopPendingAction = el.tagName === 'A'
                        ? () => { window.location.href = el.href; }
                        : () => { el.closest('form')?.submit(); };
                    const nameEl = document.getElementById('stop-discard-name');
                    if (nameEl) nameEl.textContent = window.__stopCustomer || 'this customer';
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'discard-stop' }));
                }, true);

                // "Discard changes": clear the dirty flag (so the beforeunload net stays
                // silent) and carry out the action the user clicked. "Keep editing" just
                // closes the modal via Alpine ($dispatch('close')). Delegated so it works
                // regardless of where the modal markup sits relative to this script.
                document.addEventListener('click', function (e) {
                    if (!e.target.closest('#stop-discard-confirm')) return;
                    window.__stopDirty = false;
                    window.dispatchEvent(new CustomEvent('close-modal', { detail: 'discard-stop' }));
                    if (__stopPendingAction) __stopPendingAction();
                });
            </script>

            {{-- Unsaved-changes dialog for the in-app run-sheet links (above). --}}
            <x-modal name="discard-stop" maxWidth="sm">
                <div class="p-5">
                    <h3 class="text-[15px] font-medium" style="color: var(--ink);">
                        Unsaved quantities for <span id="stop-discard-name"></span>
                    </h3>
                    <p class="mt-1 text-[13px]" style="color: var(--muted);">
                        You've entered quantities that haven't been saved. Leaving now will lose them.
                    </p>
                    <div class="mt-4 flex items-center justify-end gap-2">
                        <button type="button" class="mf-btn-secondary" x-on:click="$dispatch('close')">Keep editing</button>
                        <button type="button" id="stop-discard-confirm" class="mf-btn-danger">Discard changes</button>
                    </div>
                </div>
            </x-modal>
        @endif
    @endif
</div>
