<x-picking-layout>
    @php
        $variant = $orderItem->productVariant;
        $product = $variant->product;
        $needed = $orderItem->quantity_remaining;
        $done = $orderItem->isFullyFulfilled();
        $short = $orderItem->isShortClosed();
        $shortQty = $orderItem->quantity_short;
        $isVariable = $orderItem->isVariableWeight();
        $isBulk = $orderItem->isBulkWeighed();
        $canSeeMoney = auth()->user()->can('see-financials');
        // Nominal unit weight, as the server's weight bound reads it: only a
        // positive weight_kg gives OK/Check thresholds (a stored 0 used to
        // flag every weight as "Check").
        $expWeight = (float) $variant->weight_kg > 0 ? (float) $variant->weight_kg : null;
        $thumbClass = match ($product->type) {
            'milk' => 'mf-thumb--milk',
            'yoghurt' => 'mf-thumb--yog',
            default => str_contains(strtolower($product->name), 'garlic') ? 'mf-thumb--garlic' : 'mf-thumb--cheese',
        };

        $pickable = $batchOptions->filter(fn ($option) => $option['max'] > 0)->values();
        $initial = $pickable->first();
        $initialQty = $initial ? min($needed, $initial['max']) : 0;
        $batchMaxJson = $pickable
            ->mapWithKeys(fn ($option) => [$option['batchItem']->id => min($needed, $option['max'])])
            ->toJson();
    @endphp

    <div class="mob-head">
        <a href="{{ route('picking.show', $order) }}" class="iconbtn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        </a>
        <div class="flex-1 min-w-0">
            <h1 class="title">Pick item · {{ $position }} of {{ $order->orderItems->count() }}</h1>
            <div class="sub">{{ $order->customer->name }}</div>
        </div>
    </div>

    {{-- Product card --}}
    <div class="mob-section" style="margin-top: 14px;">
        <div class="mob-card flex items-center gap-3.5">
            <div class="mf-thumb {{ $thumbClass }}" style="width: 64px; height: 64px; font-size: 11px;">{{ $variant->size ?: strtoupper(substr($product->type, 0, 3)) }}</div>
            <div class="flex-1 min-w-0">
                <div style="font-size: 17px; font-weight: 500; letter-spacing: -0.2px;">{{ $product->name }}</div>
                <div style="font-size: 13px; color: var(--muted); margin-top: 2px;">
                    {{ $variant->name }}@if ($canSeeMoney) · {{ $variant->price_label }} @endif
                </div>
                @if ($initial)
                    <div class="flex items-center gap-1.5 mt-2 flex-wrap">
                        <span class="swatch-batch">{{ $initial['batchItem']->batch->batch_code }}</span>
                        <span style="font-size: 11px; color: var(--muted);">Produced {{ $initial['batchItem']->batch->production_date->format('d/m') }} · {{ $initial['max'] }} in stock (FIFO)</span>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if ($short)
        {{-- ── Line closed short: what was supplied, and the way back in ── --}}
        <div class="mob-section" style="margin-top: 18px;">
            <div class="mob-card" style="background: var(--warn-soft); border-color: color-mix(in oklab, var(--warn) 35%, transparent);">
                <div class="flex items-center gap-3">
                    <div style="width: 28px; height: 28px; border-radius: 14px; background: var(--warn); color: #fff; display: grid; place-items: center; flex-shrink: 0;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/></svg>
                    </div>
                    <div style="color: var(--warn-ink);">
                        <div style="font-weight: 500;">Short — {{ $orderItem->quantity_fulfilled }} of {{ $orderItem->quantity_ordered }} supplied</div>
                        <div style="font-size: 12px; margin-top: 1px;">
                            {{ $shortQty }} {{ Str::plural('unit', $shortQty) }} not available — not supplied, not billed.
                            @if (filled($orderItem->short_reason))
                                <br>{{ $orderItem->short_reason }}
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mob-section" style="margin-top: 14px;">
            <form method="POST" action="{{ route('picking.reopen', [$order, $orderItem]) }}"
                  data-confirm-title="Reopen this line?" data-confirm="It needs picking again and the order goes back to Preparing." data-confirm-label="Reopen">
                @csrf
                @if ($pickable->isNotEmpty())
                    {{-- Stock has since arrived — make picking it again the obvious move. --}}
                    <button type="submit" class="mob-fab accent w-full justify-center">Stock arrived — reopen and pick</button>
                @else
                    <button type="submit" class="mf-btn-secondary w-full justify-center" style="padding: 12px; border-radius: 10px; color: var(--warn-ink);">
                        Reopen line
                    </button>
                @endif
            </form>
        </div>

        <div class="mob-footer">
            <a href="{{ route('picking.show', $order) }}" class="mob-fab ghost">Back</a>
            @if ($skipTo)
                <a href="{{ route('picking.item', [$order, $skipTo]) }}" class="mob-fab">Next item →</a>
            @else
                <a href="{{ route('picking.show', $order) }}" class="mob-fab">Overview →</a>
            @endif
        </div>
    @elseif ($done)
        {{-- ── Line fully picked: summary + undo ── --}}
        <div class="mob-section" style="margin-top: 18px;">
            <div class="mob-card" style="background: var(--accent-soft); border-color: oklch(0.88 0.06 150);">
                <div class="flex items-center gap-3">
                    <div style="width: 28px; height: 28px; border-radius: 14px; background: var(--accent); color: #fff; display: grid; place-items: center; flex-shrink: 0;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                    </div>
                    <div style="color: var(--accent-ink);">
                        <div style="font-weight: 500;">Picked — {{ $orderItem->quantity_fulfilled }} of {{ $orderItem->quantity_ordered }}</div>
                        <div style="font-size: 12px; margin-top: 1px;">
                            @if ($isVariable && $orderItem->weight_fulfilled_kg > 0)
                                {{ number_format($orderItem->weight_fulfilled_kg, 3) }} kg recorded ·
                            @endif
                            from {{ $orderItem->orderAllocations->where('quantity_fulfilled', '>', 0)->map(fn ($a) => $a->batchItem->batch->batch_code)->unique()->implode(', ') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mob-section" style="margin-top: 14px;">
            <form method="POST" action="{{ route('picking.undo', [$order, $orderItem]) }}"
                  data-confirm-title="Undo this pick?" data-confirm="Stock goes back to the batch." data-confirm-label="Undo" data-confirm-danger>
                @csrf
                <button type="submit" class="mf-btn-secondary w-full justify-center" style="padding: 12px; border-radius: 10px; color: var(--warn-ink);">
                    Undo last pick
                </button>
            </form>
        </div>

        <div class="mob-footer">
            <a href="{{ route('picking.show', $order) }}" class="mob-fab ghost">Back</a>
            @if ($skipTo)
                <a href="{{ route('picking.item', [$order, $skipTo]) }}" class="mob-fab accent">Next item →</a>
            @else
                <a href="{{ route('picking.show', $order) }}" class="mob-fab accent">Overview →</a>
            @endif
        </div>
    @elseif ($pickable->isEmpty())
        {{-- ── Nothing pickable ── --}}
        <div class="mob-section" style="margin-top: 18px;">
            <div class="mf-flash mf-flash-warn" style="margin-bottom: 0;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                <div>
                    <strong>No available stock.</strong>
                    No ready-to-sell stock for this product — check production status or ask the office.
                </div>
            </div>
        </div>

        {{-- The way out of the dead end: settle the line so the order can ship
             without it. Kept off the fixed footer so the decisive, harder-to-
             reverse action isn't sitting under a thumb next to "Skip". --}}
        <div class="mob-section" style="margin-top: 14px;">
            <form method="POST" action="{{ route('picking.short', [$order, $orderItem]) }}"
                  data-confirm-title="Mark this line short?" data-confirm="The {{ $needed }} unpicked {{ Str::plural('unit', $needed) }} will be released back to stock and won't be supplied or billed." data-confirm-label="Mark short" data-confirm-danger>
                @csrf
                <button type="submit" class="mf-btn-secondary w-full justify-center" style="padding: 12px; border-radius: 10px; color: var(--warn-ink);">
                    Mark short — no stock
                </button>
            </form>
        </div>

        <div class="mob-footer">
            <a href="{{ route('picking.show', $order) }}" class="mob-fab ghost">Back</a>
            @if ($skipTo)
                <a href="{{ route('picking.item', [$order, $skipTo]) }}" class="mob-fab">Skip →</a>
            @endif
        </div>
    @else
        {{-- ── Pick form ── --}}
        <form method="POST" action="{{ route('picking.pick', [$order, $orderItem]) }}"
              x-data="{
                  batch: '{{ $initial['batchItem']->id }}',
                  batchMax: {{ $batchMaxJson }},
                  qty: {{ $initialQty }},
                  weights: {},
                  bulkWeight: '',
                  get maxQty() { return this.batchMax[this.batch] || 0; },
                  clamp() { if (this.qty > this.maxQty) this.qty = this.maxQty; if (this.qty < 1 && this.maxQty > 0) this.qty = 1; },
                  get total() {
                      let t = 0;
                      for (let i = 0; i < this.qty; i++) t += parseFloat(this.weights[i]) || 0;
                      return t;
                  },
                  get weightsComplete() {
                      for (let i = 0; i < this.qty; i++) if (!(parseFloat(this.weights[i]) > 0)) return false;
                      return this.qty > 0;
                  },
                  get canSubmit() {
                      if (!this.batch || this.qty < 1 || this.qty > this.maxQty) return false;
                      @if ($isVariable && ! $isBulk) return this.weightsComplete; @endif
                      @if ($isVariable && $isBulk) return parseFloat(this.bulkWeight) > 0; @endif
                      return true;
                  }
              }">
            @csrf
            <input type="hidden" name="quantity" :value="qty">

            {{-- Ordered --}}
            <div class="mob-section" style="margin-top: 18px;">
                <div class="mf-eyebrow" style="padding-left: 4px;">{{ $orderItem->quantity_fulfilled > 0 ? 'Still to pick' : 'Ordered' }}</div>
                <div class="mob-card flex items-center justify-between" style="margin-top: 6px; padding: 18px 16px;">
                    <div>
                        <div style="font-size: 13px; color: var(--muted);">Need to pick</div>
                        <div style="font-family: Fraunces, Georgia, serif; font-size: 28px; font-weight: 500; letter-spacing: -0.5px; margin-top: 2px;">
                            {{ $needed }} {{ Str::plural($variant->unit ?: 'unit', $needed) }}
                        </div>
                        @if ($orderItem->quantity_fulfilled > 0)
                            <div style="font-size: 11.5px; color: var(--muted); margin-top: 3px;">
                                {{ $orderItem->quantity_fulfilled }} of {{ $orderItem->quantity_ordered }} already picked ·
                                <button type="submit" class="mf-link" style="color: var(--warn-ink); font-size: 11.5px;"
                                    form="undo-line-form" data-confirm-title="Undo the last pick?" data-confirm="Stock goes back to the batch." data-confirm-label="Undo" data-confirm-danger>Undo</button>
                            </div>
                        @endif
                    </div>
                    @if ($canSeeMoney)
                        <div class="font-mono" style="font-size: 13px; color: var(--accent-ink);">
                            €{{ number_format($variant->calculatePrice($needed), 2) }}
                        </div>
                    @endif
                </div>
            </div>

            @if ($isVariable && ! $isBulk)
                {{-- Running total (per-piece weighing) --}}
                <div class="mob-section" style="margin-top: 18px;">
                    <div style="background: var(--ink); color: var(--bg); border-radius: 14px; padding: 16px 18px;">
                        <div class="mf-eyebrow" style="color: var(--faint);">Running total</div>
                        <div class="flex items-baseline gap-2" style="margin-top: 2px;">
                            <div style="font-family: Fraunces, Georgia, serif; font-size: 38px; font-weight: 500; letter-spacing: -1px; line-height: 1;">
                                <span x-text="total.toFixed(2)">0.00</span><span style="color: var(--faint); font-size: 22px;"> kg</span>
                            </div>
                            @if ($canSeeMoney && $variant->is_priced_by_weight)
                                <div class="font-mono ml-auto" style="font-size: 12px; color: var(--faint);">
                                    = €<span x-text="(total * {{ (float) $orderItem->unit_price }}).toFixed(2)">0.00</span>
                                </div>
                            @endif
                        </div>
                        <div class="flex items-center gap-2" style="margin-top: 12px;">
                            <div style="font-size: 11px; color: var(--faint);">
                                Of <span x-text="qty"></span> {{ Str::plural($variant->unit ?: 'piece', 2) }} picking · <span x-text="Object.entries(weights).filter(([i, w]) => i < qty && parseFloat(w) > 0).length"></span> weighed
                            </div>
                            <div class="ml-auto flex gap-1">
                                <template x-for="i in qty" :key="i">
                                    <div style="width: 6px; height: 6px; border-radius: 3px;" :style="parseFloat(weights[i-1]) > 0 ? 'background: var(--accent);' : 'background: var(--faint);'"></div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Quantity stepper --}}
            <div class="mob-section" style="margin-top: 18px;">
                <div class="mf-eyebrow" style="padding-left: 4px;">How many are you picking?</div>
                <div class="mob-card flex items-center" style="margin-top: 6px; padding: 12px 16px; gap: 18px;">
                    <button type="button" class="mob-step-btn" @click="qty = Math.max(1, qty - 1)" :disabled="qty <= 1">−</button>
                    <div class="flex-1 text-center">
                        <div style="font-family: Fraunces, Georgia, serif; font-size: 40px; font-weight: 500; letter-spacing: -1.5px; line-height: 1;" x-text="qty"></div>
                        <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">of {{ $needed }} needed · <span x-text="maxQty"></span> in batch</div>
                    </div>
                    <button type="button" class="mob-step-btn" @click="qty = Math.min(maxQty, qty + 1)" :disabled="qty >= maxQty">+</button>
                </div>
                @if ($needed > 1)
                    <div class="flex gap-2 mt-3">
                        @foreach (array_unique([1, intdiv($needed, 2) ?: 1, $needed]) as $n)
                            <button type="button" class="mf-btn-secondary flex-1 justify-center" style="padding: 10px; border-radius: 8px;"
                                @click="qty = Math.min({{ $n }}, maxQty)">{{ $n }}</button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Batch chooser --}}
            <div class="mob-section" style="margin-top: 22px;">
                <div class="mf-eyebrow" style="padding-left: 4px;">Batch</div>
                <div class="mob-card" style="margin-top: 6px; padding: 0; overflow: hidden;">
                    @foreach ($pickable as $j => $option)
                        @php $bi = $option['batchItem']; @endphp
                        <label class="flex items-center gap-3 cursor-pointer"
                               style="padding: 12px 16px; {{ $j > 0 ? 'border-top: 1px solid var(--line-2);' : '' }}"
                               :style="batch == '{{ $bi->id }}' ? 'background: color-mix(in oklab, var(--accent-soft) 50%, transparent)' : ''">
                            <input type="radio" name="batch_item_id" value="{{ $bi->id }}" x-model="batch" @change="clamp()"
                                   class="mf-checkbox" style="border-radius: 50%; width: 18px; height: 18px;">
                            <div class="flex-1">
                                <div class="font-mono" style="font-size: 13px; font-weight: 500;">{{ $bi->batch->batch_code }}</div>
                                <div style="font-size: 11px; color: var(--muted);">
                                    Produced {{ $bi->batch->production_date->format('d/m') }} · {{ min($needed, $option['max']) }} pickable{{ $j === 0 ? ' (FIFO)' : '' }}
                                    @if ($option['reserved'] > 0)
                                        · <span style="color: var(--info);">{{ $option['reserved'] }} reserved for this line</span>
                                    @endif
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            @if ($isVariable && ! $isBulk)
                {{-- Per-piece weights --}}
                <div class="mob-section" style="margin-top: 22px;">
                    <div class="mf-eyebrow" style="padding-left: 4px; margin-bottom: 8px;">{{ Str::plural(ucfirst($variant->unit ?: 'piece')) }} — weigh each one</div>
                    <template x-for="i in qty" :key="i">
                        <div class="mob-weight" :class="parseFloat(weights[i-1]) > 0 ? 'is-done' : ''">
                            <div>
                                <div style="font-size: 11px; color: var(--muted);">{{ ucfirst($variant->unit ?: 'piece') }} <span x-text="'#' + i"></span></div>
                                {{-- A typed comma becomes a decimal point: a number input
                                     would otherwise drop it and turn 5,210 into 5210 (F-29). --}}
                                <input type="number" name="weights[]" step="0.001" min="0.001" inputmode="decimal" placeholder="0.000"
                                       x-model="weights[i-1]" style="margin-top: 2px;"
                                       x-on:keydown="if ($event.key === ',') { $event.preventDefault(); document.execCommand('insertText', false, '.'); }">
                                @if ($expWeight)
                                    <div class="est">expected ~ {{ number_format($expWeight, 2) }} kg</div>
                                @endif
                            </div>
                            {{-- More than twice the expected weight is almost certainly a
                                 misplaced decimal point. The server refuses 3× outright. --}}
                            @if ($expWeight)
                                <span class="mf-tag mf-tag-accent" x-show="parseFloat(weights[i-1]) > 0 && parseFloat(weights[i-1]) <= {{ 2 * $expWeight }}"><span class="inline-block w-[5px] h-[5px] rounded-full" style="background: currentColor;"></span>OK</span>
                                <span class="mf-tag mf-tag-warn" x-show="parseFloat(weights[i-1]) > {{ 2 * $expWeight }}"><span class="inline-block w-[5px] h-[5px] rounded-full" style="background: currentColor;"></span>Check</span>
                            @else
                                <span class="mf-tag mf-tag-accent" x-show="parseFloat(weights[i-1]) > 0"><span class="inline-block w-[5px] h-[5px] rounded-full" style="background: currentColor;"></span>OK</span>
                            @endif
                        </div>
                    </template>
                    <input type="hidden" name="actual_weight_kg" :value="total > 0 ? total.toFixed(3) : ''">
                </div>
            @elseif ($isVariable && $isBulk)
                {{-- Bulk: one total weight --}}
                <div class="mob-section" style="margin-top: 22px;">
                    <div class="mf-eyebrow" style="padding-left: 4px; margin-bottom: 8px;">Total weight</div>
                    <div class="mob-weight">
                        <div>
                            <div style="font-size: 11px; color: var(--muted);">Total for <span x-text="qty"></span> {{ Str::plural($variant->unit ?: 'unit', 2) }}</div>
                            <input type="number" name="actual_weight_kg" step="0.001" min="0.001" inputmode="decimal" placeholder="0.000"
                                   x-model="bulkWeight" style="margin-top: 2px;"
                                   x-on:keydown="if ($event.key === ',') { $event.preventDefault(); document.execCommand('insertText', false, '.'); }">
                            @if ($expWeight)
                                <div class="est">expected ~ <span x-text="(qty * {{ $expWeight }}).toFixed(2)"></span> kg</div>
                            @endif
                        </div>
                        @if ($expWeight)
                            <span class="mf-tag mf-tag-accent" x-show="parseFloat(bulkWeight) > 0 && parseFloat(bulkWeight) <= qty * {{ 2 * $expWeight }}"><span class="inline-block w-[5px] h-[5px] rounded-full" style="background: currentColor;"></span>OK</span>
                            <span class="mf-tag mf-tag-warn" x-show="parseFloat(bulkWeight) > qty * {{ 2 * $expWeight }}"><span class="inline-block w-[5px] h-[5px] rounded-full" style="background: currentColor;"></span>Check</span>
                        @else
                            <span class="mf-tag mf-tag-accent" x-show="parseFloat(bulkWeight) > 0"><span class="inline-block w-[5px] h-[5px] rounded-full" style="background: currentColor;"></span>OK</span>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Partial short: the fridge has some, but not enough. Sits above
                 the fixed footer rather than in it — three thumb-sized actions
                 side by side is one too many, and this one is decisive. --}}
            {{-- Bottom margin: the fixed footer must never cover this (F-31). --}}
            <div class="mob-section" style="margin-top: 22px; margin-bottom: 24px;">
                <button type="submit" class="mf-btn-secondary w-full justify-center"
                        style="padding: 11px; border-radius: 10px; color: var(--warn-ink); font-size: 13px;"
                        form="short-line-form" data-confirm-title="Can't finish this line?" data-confirm="The {{ $needed }} unpicked {{ Str::plural('unit', $needed) }} will be released back to stock and won't be supplied or billed." data-confirm-label="Mark short" data-confirm-danger>
                    Can't finish this line — mark short
                </button>
            </div>

            <div class="mob-footer">
                @if ($skipTo)
                    <a href="{{ route('picking.item', [$order, $skipTo]) }}" class="mob-fab ghost">Skip</a>
                @endif
                <button type="submit" class="mob-fab accent" :disabled="!canSubmit">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                    <span x-text="canSubmit ? 'Picked · Next' : '{{ $isVariable && ! $isBulk ? 'Weigh each piece first' : ($isVariable ? 'Enter the weight first' : 'Picked · Next') }}'"></span>
                </button>
            </div>
        </form>

        @if ($orderItem->quantity_fulfilled > 0)
            {{-- Hidden undo form (triggered from the "already picked · Undo" link; can't nest inside the pick form). --}}
            <form id="undo-line-form" method="POST" action="{{ route('picking.undo', [$order, $orderItem]) }}" class="hidden">
                @csrf
            </form>
        @endif

        {{-- Same reason: the short button lives inside the pick form's markup,
             so its form has to be a sibling. --}}
        <form id="short-line-form" method="POST" action="{{ route('picking.short', [$order, $orderItem]) }}" class="hidden">
            @csrf
        </form>
    @endif
</x-picking-layout>
