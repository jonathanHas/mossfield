<x-app-layout>
    @php
        // One allocation card per wheel batch_item. "holdable" = free + held
        // (remaining minus order reservations); "maturing" = currently held.
        $cards = collect();
        foreach ($readyBatches as $b) {
            foreach ($b->batchItems as $it) {
                if (! str_contains(strtolower($it->productVariant->name), 'wheel')) {
                    continue;
                }
                $allocated = (int) ($it->quantity_currently_allocated ?? 0);
                $maturing = (int) $it->quantity_maturing;
                $holdable = max(0, (int) $it->quantity_remaining - $allocated);
                if ($holdable < 1 && $maturing < 1) {
                    continue;
                }
                $cards->push([
                    'item' => $it,
                    'batch' => $b,
                    'holdable' => $holdable,
                    'maturing' => $maturing,
                    'eligible' => $b->isEligibleForMaturation(),
                ]);
            }
        }
        $batchCount = $cards->pluck('batch.id')->unique()->count();
        $canConvert = auth()->user()->can('create', App\Models\CheeseConversionLog::class);
    @endphp

    <x-slot name="header">Mature conversion</x-slot>

    <div class="mc-scope px-6 py-7">
        <div class="mx-auto" style="max-width: 1180px;">
            <h1 class="font-display" style="font-size: 30px; font-weight: 500; letter-spacing: -0.5px;">Mature conversion</h1>
            <div class="mt-1 text-[14px]" style="color: var(--muted);">
                Set farmhouse wheels aside to mature — at any age. Held wheels won't be sold or auto-assigned to orders.
                Once a batch passes {{ $months }} months, release the held wheels into mature cheese.
                @if($canConvert)
                    Drag wheels — or select and use the controls — then save.
                @endif
            </div>

            @if (session('success'))
                <div class="mf-flash mf-flash-success mt-4">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="mf-flash mf-flash-error mt-4">
                    <div>
                        <ul class="list-disc list-inside text-[12px]">
                            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <div class="flex items-center gap-2.5" style="margin-top: 26px; margin-bottom: 12px;">
                <span class="text-[15px] font-semibold">Ready to mature</span>
                <span class="mc-pill-warn">{{ $batchCount }} {{ Str::plural('batch', $batchCount) }}</span>
            </div>

            @forelse ($cards as $card)
                @php $item = $card['item']; $batch = $card['batch']; $holdable = $card['holdable']; $maturing = $card['maturing']; $eligible = $card['eligible']; @endphp

                <form method="POST" action="{{ route('cheese-conversion.hold', $item) }}"
                      x-data="matureCard({{ $holdable }}, {{ $maturing }})"
                      class="mc-card"
                      @if(! $canConvert) onsubmit="return false" @endif>
                    @csrf
                    <input type="hidden" name="quantity" :value="mature.length">

                    {{-- batch header --}}
                    <div class="mc-card-head">
                        <span class="font-mono text-[14px] font-semibold">{{ $batch->batch_code }}</span>
                        <span class="text-[14px] font-semibold">{{ $batch->product->name }}</span>
                        @if($eligible)
                            <span class="mc-pill-accent">
                                <span class="mc-dot" style="background: var(--accent);"></span>Ready to release
                            </span>
                        @else
                            <span class="mc-pill-warn">Maturing — eligible {{ optional($batch->production_date)->copy()->addMonths($months)->format('d/m/Y') }}</span>
                        @endif
                        <div class="flex-1"></div>
                        <div class="flex gap-6">
                            <x-mature.meta label="Produced" :value="$batch->production_date->format('d/m/Y')" />
                            <x-mature.meta label="Age" :value="(int) $batch->production_date->diffInMonths(now()).' months'" />
                            <x-mature.meta label="Raw milk" :value="number_format($batch->raw_milk_litres, 1).'L'" />
                        </div>
                        @if($canConvert && $eligible && $maturing > 0)
                            <button type="submit" formaction="{{ route('cheese-conversion.release', $item) }}" formnovalidate
                                    class="mc-btn mc-btn-mature" style="margin-left: 8px;"
                                    onclick="return confirm('Release {{ $maturing }} held wheel(s) into mature cheese?')">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z"/><path d="M12 6v6l4 2"/></svg>
                                Release {{ $maturing }} to mature
                            </button>
                        @endif
                        <a href="{{ route('batches.show', $batch) }}" class="mc-btn mc-btn-secondary" style="margin-left: 8px;">View batch</a>
                    </div>

                    {{-- allocation row --}}
                    <div class="mc-alloc">
                        {{-- LEFT — farmhouse --}}
                        <div class="mc-zone"
                             :class="dropTarget === 'available' && {{ $canConvert ? 'true' : 'false' }} ? 'mc-zone-over-farm' : ''"
                             @if($canConvert)
                                 @dragover.prevent="dropTarget = 'available'"
                                 @dragleave="dropTarget = (dropTarget === 'available' ? null : dropTarget)"
                                 @drop.prevent="onDrop('available')"
                             @endif>
                            <div class="mc-zone-head">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="mc-swatch" style="background: var(--farm); box-shadow: inset 0 0 0 1.5px var(--farm-rim);"></span>
                                        <span class="text-[14px] font-bold">Farmhouse</span>
                                        <span class="mc-count" x-text="available.length"></span>
                                    </div>
                                    <div class="text-[11.5px] mt-0.5" style="color: var(--muted);">Staying as farmhouse</div>
                                </div>
                                @if($canConvert)
                                    <button type="button" class="mc-btn mc-btn-ghost" style="padding: 4px 8px; font-size: 11.5px;"
                                            @click="selectAll('available', selAvail !== available.length)"
                                            x-text="(selAvail === available.length && available.length) ? 'Clear' : 'Select all'"></button>
                                @endif
                            </div>

                            <template x-if="available.length === 0">
                                <div class="mc-empty">No wheels left as farmhouse</div>
                            </template>
                            <div class="mc-grid" x-show="available.length > 0">
                                <template x-for="w in available" :key="w.id">
                                    <div class="mc-wheel mc-wheel-farm"
                                         :class="w.selected ? 'mc-wheel-sel-farm' : ''"
                                         :draggable="{{ $canConvert ? 'true' : 'false' }}"
                                         @if($canConvert) @click="toggle(w.id)" @dragstart="onDragStart(w.id)" @endif
                                         title="Farmhouse wheel">
                                        <span class="mc-wheel-check" x-show="w.selected">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                                        </span>
                                    </div>
                                </template>
                            </div>
                            <template x-if="selAvail > 0">
                                <div class="mc-selcount" style="color: var(--farm-ink);">
                                    <span class="mc-dot" style="background: var(--farm);"></span><span x-text="selAvail + ' selected'"></span>
                                </div>
                            </template>
                        </div>

                        {{-- CENTER — controls --}}
                        <div class="mc-controls">
                            @if($canConvert)
                                <button type="button" class="mc-btn mc-btn-mature" style="padding: 9px 14px;"
                                        :style="selAvail === 0 ? 'opacity:.5;cursor:not-allowed' : ''"
                                        :disabled="selAvail === 0"
                                        @click="moveSelected('available', 'mature')">
                                    Mature
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                                </button>
                                <button type="button" class="mc-btn mc-btn-secondary" style="padding: 9px 14px;"
                                        :style="selMature === 0 ? 'opacity:.5;cursor:not-allowed' : ''"
                                        :disabled="selMature === 0"
                                        @click="moveSelected('mature', 'available')">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>
                                    Return
                                </button>

                                <div style="width: 130px; height: 1px; background: var(--line); margin: 4px 0;"></div>

                                <div class="text-[10.5px] font-semibold" style="color: var(--faint); text-transform: uppercase; letter-spacing: 0.5px;">Quick allocate</div>
                                <div class="mc-stepper">
                                    <button type="button" @click="step(-1)">−</button>
                                    <span class="font-mono" x-text="stepN"></span>
                                    <button type="button" @click="step(1)">+</button>
                                </div>
                                <div class="flex gap-1.5">
                                    <button type="button" class="mc-btn mc-btn-secondary" style="padding: 6px 10px; font-size: 11.5px;"
                                            :style="available.length === 0 ? 'opacity:.5' : ''" :disabled="available.length === 0"
                                            @click="stepMove('available', 'mature')">
                                        <span x-text="'Next ' + Math.min(stepN, available.length)"></span>
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                                    </button>
                                    <button type="button" class="mc-btn mc-btn-ghost" style="padding: 6px 10px; font-size: 11.5px; color: var(--muted);"
                                            :style="mature.length === 0 ? 'opacity:.5' : ''" :disabled="mature.length === 0"
                                            @click="stepMove('mature', 'available')">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>
                                    </button>
                                </div>
                            @else
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--faint)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z"/><path d="M12 6v6l4 2"/></svg>
                                <div class="text-[11.5px] text-center" style="color: var(--muted);">View only —<br>office staff convert</div>
                            @endif
                        </div>

                        {{-- RIGHT — maturing --}}
                        <div class="mc-zone mc-zone-mature"
                             :class="dropTarget === 'mature' && {{ $canConvert ? 'true' : 'false' }} ? 'mc-zone-over-mature' : ''"
                             @if($canConvert)
                                 @dragover.prevent="dropTarget = 'mature'"
                                 @dragleave="dropTarget = (dropTarget === 'mature' ? null : dropTarget)"
                                 @drop.prevent="onDrop('mature')"
                             @endif>
                            <div class="mc-zone-head">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <span style="color: var(--mature-ink);">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z"/><path d="M12 6v6l4 2"/></svg>
                                        </span>
                                        <span class="text-[14px] font-bold" style="color: var(--mature-ink);">Maturing</span>
                                        <span class="mc-count mc-count-mature" x-text="mature.length"></span>
                                    </div>
                                    <div class="text-[11.5px] mt-0.5" style="color: var(--muted);">Allocated to become mature</div>
                                </div>
                                @if($canConvert)
                                    <button type="button" class="mc-btn mc-btn-ghost" style="padding: 4px 8px; font-size: 11.5px; color: var(--mature-ink);"
                                            x-show="mature.length > 0"
                                            @click="selectAll('mature', selMature !== mature.length)"
                                            x-text="(selMature === mature.length && mature.length) ? 'Clear' : 'Select all'"></button>
                                @endif
                            </div>

                            <template x-if="mature.length === 0">
                                <div class="mc-empty mc-empty-mature">{{ $canConvert ? 'Drag or send wheels here to mature them' : 'No wheels allocated yet' }}</div>
                            </template>
                            <div class="mc-grid" x-show="mature.length > 0">
                                <template x-for="w in mature" :key="w.id">
                                    <div class="mc-wheel mc-wheel-mature"
                                         :class="w.selected ? 'mc-wheel-sel-mature' : ''"
                                         :draggable="{{ $canConvert ? 'true' : 'false' }}"
                                         @if($canConvert) @click="toggle(w.id)" @dragstart="onDragStart(w.id)" @endif
                                         title="Allocated to mature">
                                        <span class="mc-wheel-check" x-show="w.selected">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                                        </span>
                                    </div>
                                </template>
                            </div>
                            <template x-if="selMature > 0">
                                <div class="mc-selcount" style="color: var(--mature-ink);">
                                    <span class="mc-dot" style="background: var(--mature);"></span><span x-text="selMature + ' selected'"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- confirm footer --}}
                    <div class="mc-foot">
                        <div class="flex items-center gap-[18px] text-[13px] flex-1">
                            <span class="inline-flex items-center gap-[7px]">
                                <span class="mc-chip" style="background: var(--farm); box-shadow: inset 0 0 0 1.5px var(--farm-rim);"></span>
                                <b class="font-mono" x-text="available.length"></b> staying farmhouse
                            </span>
                            <span style="color: var(--faint);">→</span>
                            <span class="inline-flex items-center gap-[7px]">
                                <span class="mc-chip" style="background: var(--mature); box-shadow: inset 0 0 0 1.5px var(--mature-rim);"></span>
                                <b class="font-mono" style="color: var(--mature-ink);" x-text="mature.length"></b> set aside to mature
                            </span>
                        </div>
                        @if($canConvert)
                            <button type="button" class="mc-btn mc-btn-ghost" style="color: var(--muted);" @click="reset()">Reset</button>
                            <button type="submit" class="mc-btn mc-btn-mature" style="padding: 9px 16px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"/></svg>
                                <span x-text="'Save maturing · ' + mature.length"></span>
                            </button>
                        @endif
                    </div>
                </form>
            @empty
                <div class="mf-panel p-10 text-center">
                    <h3 class="text-[14px] font-semibold">No farmhouse wheels to set aside</h3>
                    <p class="mt-1 text-[13px]" style="color: var(--muted);">
                        Once a cheese batch has whole wheels in stock, they'll appear here to set aside for maturing.
                    </p>
                    <div class="mt-4">
                        <a href="{{ route('batches.index', ['type' => 'cheese']) }}" class="mf-btn-secondary">View all cheese batches</a>
                    </div>
                </div>
            @endforelse

            @if($canConvert && $cards->isNotEmpty())
                <div class="mt-4 text-[12.5px] flex items-center gap-2" style="color: var(--muted);">
                    <span class="font-mono" style="background: var(--line-2); padding: 2px 7px; border-radius: 4px; font-size: 11px;">tip</span>
                    Click wheels to select multiple, then use <b style="color: var(--ink-2);">Mature →</b> / <b style="color: var(--ink-2);">← Return</b>. Or drag any wheel straight across.
                </div>
            @endif
        </div>
    </div>

    <script>
        function matureCard(total, held) {
            return {
                // total = holdable wheels (free + already held). Seed the first
                // `held` into the Maturing zone so saved holds persist on reload.
                wheels: Array.from({ length: total }, (_, i) => ({ id: i + 1, zone: i < held ? 'mature' : 'available', selected: false })),
                stepN: Math.min(5, total) || 1,
                dropTarget: null,
                dragId: null,
                get available() { return this.wheels.filter(w => w.zone === 'available'); },
                get mature() { return this.wheels.filter(w => w.zone === 'mature'); },
                get selAvail() { return this.available.filter(w => w.selected).length; },
                get selMature() { return this.mature.filter(w => w.selected).length; },
                toggle(id) { const w = this.wheels.find(x => x.id === id); if (w) w.selected = !w.selected; },
                moveZone(ids, to) { this.wheels.forEach(w => { if (ids.includes(w.id)) { w.zone = to; w.selected = false; } }); },
                moveSelected(from, to) { const ids = this.wheels.filter(w => w.zone === from && w.selected).map(w => w.id); if (ids.length) this.moveZone(ids, to); },
                stepMove(from, to) { const ids = this.wheels.filter(w => w.zone === from).slice(0, this.stepN).map(w => w.id); if (ids.length) this.moveZone(ids, to); },
                selectAll(zone, val) { this.wheels.forEach(w => { if (w.zone === zone) w.selected = val; }); },
                reset() { this.wheels.forEach(w => { w.zone = 'available'; w.selected = false; }); },
                step(d) { this.stepN = Math.max(1, Math.min(total, this.stepN + d)); },
                onDragStart(id) { this.dragId = id; },
                onDrop(to) {
                    this.dropTarget = null;
                    const id = this.dragId;
                    if (id == null) return;
                    const dragged = this.wheels.find(w => w.id === id);
                    if (!dragged || dragged.zone === to) return;
                    const selIds = this.wheels.filter(w => w.zone === dragged.zone && w.selected).map(w => w.id);
                    const ids = (selIds.includes(id) && selIds.length) ? selIds : [id];
                    this.moveZone(ids, to);
                    this.dragId = null;
                },
            };
        }
    </script>
</x-app-layout>
