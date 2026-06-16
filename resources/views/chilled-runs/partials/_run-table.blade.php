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

{{-- Run sheet table --}}
<div class="mf-panel">
    <div class="mf-panel-header">
        <span class="text-[13px] font-semibold">{{ $activeRun->day_label }} &middot; {{ $activeRun->name }} run</span>
        <span class="mf-eyebrow">{{ $activeRun->driver ? 'Driver: '.$activeRun->driver : '' }}</span>
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
        <div class="overflow-x-auto">
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
                            <tr id="stop-{{ $customer->id }}" class="is-editing"
                                x-data="stopEditor({
                                    current: {{ Js::from((object) $sheet['editQuantities']) }},
                                    history: {{ Js::from($sheet['history']) }},
                                    fixedIds: {{ Js::from($milkCols->pluck('id')->merge($yogCols->pluck('id'))->values()) }},
                                    cheeseColIds: {{ Js::from($cheeseCols->pluck('id')->values()) }},
                                    labels: {{ Js::from((object) $variantLabels->all()) }},
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
                                        <a href="{{ route('chilled-runs.index', $sheetParams) }}#stop-{{ $customer->id }}" class="mf-btn-ghost" style="padding: 4px 8px;">Cancel</a>
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
                                                        <option value="{{ $variant->id }}">{{ $variant->name }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @endforeach
                                        </select>
                                    @endif
                                    @if ($sheet['extras'][$customer->id] !== '')
                                        <div class="mt-1.5" style="color: var(--muted); font-size: 11.5px;">{{ $sheet['extras'][$customer->id] }}</div>
                                    @endif
                                </td>
                            </tr>
                        @else
                            {{-- ── Display mode ── --}}
                            <tr id="stop-{{ $customer->id }}" class="{{ $isLoaded ? 'is-loaded' : '' }}">
                                <td class="pad">
                                    @if ($hasOrders)
                                        @can('load', $orders->first())
                                            @foreach ($orders as $order)
                                                <form method="POST" action="{{ route('chilled-runs.toggle-loaded', $order) }}">
                                                    @csrf
                                                    <input type="hidden" name="run" value="{{ $activeRun->id }}">
                                                    <input type="hidden" name="date" value="{{ request('date') }}">
                                                    <button type="submit"
                                                            class="mf-run-check{{ $order->loaded_at ? ' on' : '' }}"
                                                            aria-label="{{ $order->loaded_at ? 'Loaded — tap to unmark' : 'Mark loaded' }}">
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M20 6L9 17l-5-5" />
                                                        </svg>
                                                    </button>
                                                </form>
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
                                <td class="cust">
                                    <div class="nm">{{ $customer->name }}</div>
                                    @if ($customer->address || $customer->city)
                                        <div class="ad">{{ collect([$customer->address, $customer->city, $customer->postal_code])->filter()->implode(', ') }}</div>
                                    @endif
                                    @if ($hasOrders)
                                        <div class="so">
                                            {{ $orders->pluck('order_number')->implode(' · ') }}
                                            @if ($orders->contains(fn ($o) => $o->status === 'pending'))
                                                <span class="mf-tag mf-tag-warn" style="margin-left: 4px;">Pending</span>
                                            @endif
                                        </div>
                                    @endif
                                    @if ($canEnter)
                                        <div class="mt-1.5">
                                            @if ($row['editable'])
                                                <a class="mf-link text-[12px]"
                                                   href="{{ route('chilled-runs.index', array_merge($sheetParams, ['edit' => $customer->id])) }}#stop-{{ $customer->id }}">
                                                    {{ $hasOrders ? 'Edit' : 'Enter order' }}
                                                </a>
                                            @elseif ($orders->count() > 1)
                                                <a class="mf-link text-[12px]" href="{{ route('orders.show', $orders->first()) }}">Multiple orders — open</a>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                @foreach ($milkCols->concat($yogCols)->concat($cheeseCols) as $variant)
                                    @php
                                        $tint = $variant->product->type === 'milk' ? 'col-milk' : ($variant->product->type === 'yoghurt' ? 'col-yog' : 'col-cheese');
                                        $qty = $sheet['qtyMap'][$customer->id][$variant->id] ?? 0;
                                    @endphp
                                    <td class="qty {{ $qty === 0 ? 'zero' : '' }} {{ $tint }}">{{ $qty === 0 ? '·' : $qty }}</td>
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
            </script>
        @endif
    @endif
</div>
