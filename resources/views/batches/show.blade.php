<x-app-layout>
    <x-slot name="header">Batch {{ $batch->batch_code }}</x-slot>

    @php
        $typeTone = match($batch->product->type) {
            'milk' => 'info',
            'yoghurt' => 'accent',
            'cheese' => 'warn',
            default => 'neutral',
        };
        $statusTone = match($batch->status) {
            'active' => 'accent',
            'sold_out' => 'danger',
            default => 'neutral',
        };
    @endphp

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <div class="text-[12px] font-mono" style="color: var(--muted);">Batch · {{ $batch->product->name }}</div>
                <h1 class="mt-0.5 text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">
                    <span class="font-mono">{{ $batch->batch_code }}</span>
                </h1>
                <div class="mt-1 flex items-center gap-2">
                    <span class="mf-tag mf-tag-{{ $typeTone }}">{{ ucfirst($batch->product->type) }}</span>
                    <span class="mf-tag mf-tag-{{ $statusTone }}">{{ ucfirst(str_replace('_', ' ', $batch->status)) }}</span>
                    @if($batch->isExpired())
                        <span class="mf-tag mf-tag-danger">Expired</span>
                    @elseif(!$batch->isReadyToSell())
                        <span class="mf-tag mf-tag-warn">Maturing</span>
                    @else
                        <span class="mf-tag mf-tag-accent">Ready</span>
                    @endif
                </div>
            </div>
            <div class="flex gap-2">
                {{-- Expired stock: one click writes off everything that isn't owed to an order. --}}
                @if($batch->isExpired() && ($writableUnits = $batch->writableUnits()) > 0)
                    @can('create', App\Models\StockWriteOff::class)
                        <form method="POST" action="{{ route('batches.write-offs.expired', $batch) }}">
                            @csrf
                            <button type="submit" class="mf-btn-danger"
                                    data-confirm-title="Write off expired stock?"
                                    data-confirm="Write off {{ $writableUnits }} {{ Str::plural('unit', $writableUnits) }} of {{ $batch->batch_code }} as Expired? Anything reserved for orders stays. You can reverse it from the Write-offs panel."
                                    data-confirm-label="Write off" data-confirm-danger>
                                Write off expired stock ({{ $writableUnits }} {{ Str::plural('unit', $writableUnits) }})
                            </button>
                        </form>
                    @endcan
                @endif
                <a href="{{ route('batches.index') }}" class="mf-btn-ghost">← All batches</a>
                @can('update', $batch)
                    <a href="{{ route('batches.edit', $batch) }}" class="mf-btn-secondary">Edit</a>
                @endcan
            </div>
        </div>

        <div class="mf-panel mb-4">
            <div class="mf-panel-header">
                <div class="text-[13px] font-semibold">Batch information</div>
            </div>
            <dl class="px-4 py-3 text-[13px] grid grid-cols-1 md:grid-cols-3 gap-x-8 gap-y-3">
                <div>
                    <dt style="color: var(--muted);" class="text-[11.5px] uppercase font-medium" style="letter-spacing: 0.4px;">Batch code</dt>
                    <dd class="mt-0.5 font-mono font-semibold">{{ $batch->batch_code }}</dd>
                </div>
                <div>
                    <dt style="color: var(--muted);" class="text-[11.5px] uppercase font-medium" style="letter-spacing: 0.4px;">Product</dt>
                    <dd class="mt-0.5">{{ $batch->product->name }}</dd>
                </div>
                <div>
                    <dt style="color: var(--muted);" class="text-[11.5px] uppercase font-medium" style="letter-spacing: 0.4px;">Production date</dt>
                    <dd class="mt-0.5 font-mono">{{ $batch->production_date->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt style="color: var(--muted);" class="text-[11.5px] uppercase font-medium" style="letter-spacing: 0.4px;">Raw milk used</dt>
                    <dd class="mt-0.5 font-mono">{{ $batch->hasNoRawMilkOfItsOwn() ? '—' : number_format($batch->raw_milk_litres, 2).' L' }}</dd>
                    @if($batch->wheels_produced && $batch->product->type === 'cheese')
                        <div class="text-[11.5px] mt-0.5" style="color: var(--muted);">{{ $batch->wheels_produced }} wheels produced</div>
                    @endif
                </div>
                @if($batch->finished_product_weight > 0)
                @php
                    // Measured where a wheel was weighed (dispatch, cutting), nominal
                    // elsewhere (on hand, converted, pre-2026-09-13 cuts). The tilde
                    // means "partly estimated"; see BatchItem::weight_breakdown.
                    $coverage = $batch->weight_coverage;
                    $partlyNominal = $coverage['nominal_kg'] > 0;
                    $approx = $partlyNominal ? '~' : '';
                @endphp
                <div>
                    <dt style="color: var(--muted);" class="text-[11.5px] uppercase font-medium" style="letter-spacing: 0.4px;">Finished weight</dt>
                    <dd class="mt-0.5 font-mono" title="{{ number_format($coverage['measured_kg'], 3) }} kg measured · {{ number_format($coverage['nominal_kg'], 3) }} kg nominal">{{ $approx }}{{ number_format($batch->finished_product_weight, 2) }} kg</dd>
                    @if($coverage['has_wheels'])
                        <div class="text-[11.5px] mt-0.5" style="color: var(--muted);">
                            {{ $coverage['wheels_weighed'] }} of {{ $coverage['wheels_produced'] }} wheels weighed
                            @unless($coverage['reconciles'])
                                · <span style="color: var(--danger);" title="on hand + dispatched + cut + converted + written off ≠ produced">does not reconcile</span>
                            @endunless
                        </div>
                    @endif
                    @if($batch->production_yield)
                        <div class="text-[11.5px] mt-0.5" style="color: var(--muted);">
                            Yield {{ $approx }}{{ number_format($batch->production_yield * 100, 1) }}%@if(! $coverage['has_wheels'] || $coverage['measured_kg'] <= 0) (nominal)@endif
                        </div>
                    @endif
                </div>
                @endif
                @if($batch->ready_date)
                <div>
                    <dt style="color: var(--muted);" class="text-[11.5px] uppercase font-medium" style="letter-spacing: 0.4px;">Ready date</dt>
                    <dd class="mt-0.5 font-mono">{{ $batch->ready_date->format('d/m/Y') }}</dd>
                    @if(!$batch->isReadyToSell())
                        <div class="text-[11.5px] mt-0.5" style="color: var(--warn-ink);">{{ $batch->readyIn() }}</div>
                    @else
                        <div class="text-[11.5px] mt-0.5" style="color: var(--accent-ink);">Ready now</div>
                    @endif
                </div>
                @endif
                <div>
                    <dt style="color: var(--muted);" class="text-[11.5px] uppercase font-medium" style="letter-spacing: 0.4px;">Expiry date</dt>
                    @if($batch->expiry_date)
                        <dd class="mt-0.5 font-mono">{{ $batch->expiry_date->format('d/m/Y') }}</dd>
                        @if($batch->isExpired())
                            <div class="text-[11.5px] mt-0.5" style="color: var(--danger);">Expired</div>
                        @endif
                    @else
                        <dd class="mt-0.5" style="color: var(--muted);">None — stays sellable until sold</dd>
                    @endif
                </div>
                <div>
                    <dt style="color: var(--muted);" class="text-[11.5px] uppercase font-medium" style="letter-spacing: 0.4px;">Stock remaining</dt>
                    <dd class="mt-0.5 font-mono font-semibold text-[15px]" style="color: {{ $batch->remaining_stock > 0 ? 'var(--accent-ink)' : 'var(--danger)' }};">
                        {{ $batch->remaining_label }}
                    </dd>
                </div>
                @php $writtenOff = $batch->writtenOffSummary(); @endphp
                @if($writtenOff['units'] > 0)
                    <div>
                        <dt style="color: var(--muted);" class="text-[11.5px] uppercase font-medium" style="letter-spacing: 0.4px;">Written off</dt>
                        <dd class="mt-0.5 font-mono">
                            <a href="#write-offs" class="mf-link">{{ $writtenOff['units'] }} {{ Str::plural('unit', $writtenOff['units']) }}</a>@if($writtenOff['weighed_kg'] > 0) · {{ number_format($writtenOff['weighed_kg'], 3) }} kg measured @endif
                        </dd>
                    </div>
                @endif
            </dl>

            @if($batch->notes)
                <div class="px-4 py-3" style="border-top: 1px solid var(--line-2);">
                    <div class="text-[11.5px] uppercase font-medium" style="color: var(--muted); letter-spacing: 0.4px;">Production notes</div>
                    <p class="mt-1 text-[13px]">{{ $batch->notes }}</p>
                </div>
            @endif
        </div>

        <div class="mf-panel mb-4">
            <div class="mf-panel-header">
                <div class="text-[13px] font-semibold">Stock breakdown by variant</div>
            </div>

            @if($batch->batchItems->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th class="mf-th">Variant</th>
                                <th class="mf-th">Size</th>
                                <th class="mf-th">Unit weight</th>
                                <th class="mf-th text-right">Produced</th>
                                <th class="mf-th text-right">On hand</th>
                                <th class="mf-th text-right">Sold</th>
                                <th class="mf-th text-right" title="Wheels cut into vacuum packs">Cut</th>
                                <th class="mf-th text-right" title="Wheels released into the Mature product">Converted</th>
                                <th class="mf-th text-right" title="Lost — spoiled, damaged, sampled, expired (see Write-offs)">Written off</th>
                                <th class="mf-th text-right" title="Remaining units × the variant's unit weight — nothing on the shelf has been weighed yet">On hand (nominal)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($batch->batchItems as $item)
                                <tr style="border-top: 1px solid var(--line-2);">
                                    <td class="mf-td font-medium">{{ $item->productVariant->name }}</td>
                                    {{-- "wheel wheel" when size and unit are the same word (F-11). --}}
                                    <td class="mf-td" style="color: var(--muted);">{{ $item->productVariant->size }}@if(strcasecmp((string) $item->productVariant->size, (string) $item->productVariant->unit) !== 0) {{ $item->productVariant->unit }}@endif</td>
                                    <td class="mf-td font-mono" style="color: var(--muted);">{{ $item->unit_weight_kg ? number_format($item->unit_weight_kg, 3) . ' kg' : '—' }}</td>
                                    @php
                                        // Every unit in exactly one state — the same breakdown the
                                        // finished weight above is derived from, so the two agree
                                        // (and agree with /stock). Finding 8: "Sold" used to be
                                        // produced − remaining, which swallowed cut and converted.
                                        $wb = $item->weight_breakdown;
                                        // Written-off units left the shelf but weren't sold.
                                        $soldUnits = $wb['kind'] === 'wheel' ? $wb['dispatched'] : max(0, $wb['produced'] - $wb['on_hand'] - $wb['written_off']);
                                    @endphp
                                    <td class="mf-td font-mono text-right">{{ number_format($item->quantity_produced) }}</td>
                                    <td class="mf-td font-mono text-right font-medium" style="color: {{ $item->quantity_remaining > 0 ? 'var(--accent-ink)' : 'var(--danger)' }};">
                                        {{ number_format($item->quantity_remaining) }}
                                    </td>
                                    <td class="mf-td font-mono text-right" style="color: var(--muted);">{{ number_format($soldUnits) }}</td>
                                    <td class="mf-td font-mono text-right" style="color: var(--muted);">{{ $wb['kind'] === 'wheel' ? number_format($wb['cut']) : '—' }}</td>
                                    <td class="mf-td font-mono text-right" style="color: var(--muted);">{{ $wb['kind'] === 'wheel' ? number_format($wb['converted']) : '—' }}</td>
                                    <td class="mf-td font-mono text-right" style="color: {{ $wb['written_off'] > 0 ? 'var(--danger)' : 'var(--muted)' }};">{{ $wb['written_off'] > 0 ? number_format($wb['written_off']) : '—' }}</td>
                                    <td class="mf-td font-mono text-right" style="color: var(--muted);">{{ $item->unit_weight_kg ? '~' . number_format($item->total_weight, 2) . ' kg' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-4 py-8 text-center" style="color: var(--muted);">No batch items recorded.</div>
            @endif
        </div>

        @if($batch->product->type === 'cheese' && $batch->cuttingLogs->count() > 0)
            <div class="mf-panel mb-4">
                <div class="mf-panel-header">
                    <div class="flex-1"><div class="text-[13px] font-semibold">Cheese cutting history</div></div>
                    {{-- Trim loss = Σ (wheel − packs) over the cuts whose wheel was weighed.
                         Unweighed (pre-2026-09-13) cuts are counted, never estimated. --}}
                    <div class="text-[12px]" style="color: var(--muted);">
                        Trim loss
                        <span class="font-mono font-semibold" style="color: var(--ink);">{{ number_format($batch->trim_loss_kg, 3) }} kg</span>
                        over {{ $batch->measured_cuts_count }} weighed {{ Str::plural('cut', $batch->measured_cuts_count) }}
                        @if($batch->unmeasured_cuts_count > 0)
                            · {{ $batch->unmeasured_cuts_count }} not weighed
                        @endif
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th class="mf-th">Cut date</th>
                                <th class="mf-th">Source wheel</th>
                                <th class="mf-th text-right">Wheel</th>
                                <th class="mf-th text-right">Vac packs</th>
                                <th class="mf-th text-right">Packs weight</th>
                                <th class="mf-th text-right">Waste</th>
                                <th class="mf-th text-right">Avg pack</th>
                                <th class="mf-th">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($batch->cuttingLogs as $log)
                                <tr style="border-top: 1px solid var(--line-2);">
                                    <td class="mf-td font-mono">{{ $log->cut_date->format('d/m/Y') }}</td>
                                    <td class="mf-td">{{ $log->sourceBatchItem->productVariant->name }}</td>
                                    <td class="mf-td font-mono text-right">
                                        @if($log->isMeasured())
                                            {{ number_format($log->wheel_weight_kg, 3) }} kg
                                        @else
                                            <span style="color: var(--faint);" title="Cut before wheel weights were recorded">not weighed</span>
                                        @endif
                                    </td>
                                    <td class="mf-td font-mono text-right">{{ number_format($log->vacuum_packs_created) }}</td>
                                    <td class="mf-td font-mono text-right">{{ number_format($log->total_weight_kg, 3) }} kg</td>
                                    <td class="mf-td font-mono text-right">
                                        @if($log->waste_kg !== null)
                                            {{ number_format($log->waste_kg, 3) }} kg
                                        @else
                                            <span style="color: var(--faint);">—</span>
                                        @endif
                                    </td>
                                    <td class="mf-td font-mono text-right">{{ number_format($log->average_pack_weight, 3) }} kg</td>
                                    <td class="mf-td" style="color: var(--muted);">{{ $log->notes ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @include('batches.partials._write-offs')

        <div class="mf-panel">
            <div class="mf-panel-header">
                <div class="text-[13px] font-semibold">Actions</div>
            </div>
            <div class="px-4 py-3">
                <div class="flex flex-wrap gap-2">
                    @if($batch->product->type === 'cheese')
                        @php
                            // Free wheels only (not raw quantity_remaining): a held or
                            // reserved wheel can't be cut, so it mustn't be offered.
                            $availableWheels = $batch->batchItems->filter(function($item) {
                                return str_contains(strtolower($item->productVariant->name), 'wheel') && $item->available_quantity > 0;
                            });
                            $isReady = $batch->ready_date === null || $batch->ready_date <= now();

                            $isMatureProduct = $batch->product->name === 'Mossfield Mature Cheese';
                            $convertibleWheels = $batch->batchItems->filter(function($item) {
                                return str_contains(strtolower($item->productVariant->name), 'wheel') && $item->available_quantity > 0;
                            });
                            $matureMonths = (int) config('mossfield.mature_conversion_months', 5);
                            $matureDate = $batch->production_date?->copy()->addMonths($matureMonths);
                        @endphp
                        @if($availableWheels->count() > 0 && $isReady)
                            @can('create', App\Models\CheeseCuttingLog::class)
                                @foreach($availableWheels as $wheelItem)
                                    <a href="{{ route('cheese-cutting.create', $wheelItem) }}" class="mf-btn-primary">
                                        Cut {{ $wheelItem->productVariant->name }}
                                        <span class="text-[11.5px] font-mono ml-1" style="opacity: 0.8;">({{ $wheelItem->available_quantity }} left)</span>
                                    </a>
                                @endforeach
                            @endcan
                            <a href="{{ route('cheese-cutting.index') }}" class="mf-btn-secondary">All cutting</a>
                        @else
                            <button class="mf-btn-secondary cursor-not-allowed" style="color: var(--faint);" disabled>
                                @if($batch->ready_date && $batch->ready_date > now())
                                    Cheese not ready ({{ $batch->ready_date->format('d/m/Y') }})
                                @else
                                    No wheels available
                                @endif
                            </button>
                        @endif

                        {{-- Farmhouse → Mature conversion (not offered on mature batches).
                             Wheel selection happens on the interactive conversion page. --}}
                        @unless($isMatureProduct)
                            @if($batch->isEligibleForMaturation() && $convertibleWheels->count() > 0)
                                @can('create', App\Models\CheeseConversionLog::class)
                                    <a href="{{ route('cheese-conversion.index') }}" class="mf-btn-secondary">
                                        Convert to Mature
                                        <span class="text-[11.5px] font-mono ml-1" style="opacity: 0.8;">({{ $convertibleWheels->sum('available_quantity') }} free)</span>
                                    </a>
                                @endcan
                            @elseif(! $batch->isEligibleForMaturation() && $matureDate)
                                <button class="mf-btn-secondary cursor-not-allowed" style="color: var(--faint);" disabled>
                                    Eligible for mature on {{ $matureDate->format('d/m/Y') }}
                                </button>
                            @endif
                        @endunless
                    @endif
                </div>
                @php
                    // Releases that landed in this (mature) batch — each can be
                    // returned to the source farmhouse hold while its wheels are intact.
                    $releaseLogs = $batch->batchItems->flatMap(fn ($i) => $i->targetConversionLogs->map(fn ($l) => ['log' => $l, 'item' => $i]));
                @endphp
                @if($batch->sourceBatch || $batch->matureBatches->isNotEmpty() || $releaseLogs->isNotEmpty())
                    <div class="mt-3 pt-3 text-[12px] space-y-1" style="border-top: 1px solid var(--line-2); color: var(--muted);">
                        @if($batch->sourceBatch)
                            <div>
                                Matured from
                                <a href="{{ route('batches.show', $batch->sourceBatch) }}" class="mf-link font-mono">{{ $batch->sourceBatch->batch_code }}</a>
                                <span>({{ $batch->sourceBatch->product->name }})</span>
                            </div>
                        @endif
                        @foreach($batch->matureBatches as $matureBatch)
                            <div>
                                Matured into
                                <a href="{{ route('batches.show', $matureBatch) }}" class="mf-link font-mono">{{ $matureBatch->batch_code }}</a>
                                <span>({{ $matureBatch->product->name }})</span>
                            </div>
                        @endforeach

                        @foreach($releaseLogs as $entry)
                            @php $log = $entry['log']; $matureItem = $entry['item']; $canUndo = $matureItem->available_quantity >= $log->wheels_converted; @endphp
                            <div class="flex items-center gap-2 flex-wrap">
                                <span>
                                    Released {{ $log->wheels_converted }} wheel(s) on {{ $log->conversion_date->format('d/m/Y') }}
                                    @if($log->sourceBatchItem?->batch)
                                        from <span class="font-mono">{{ $log->sourceBatchItem->batch->batch_code }}</span>
                                    @endif
                                </span>
                                @can('delete', $log)
                                    @if($canUndo)
                                        <form method="POST" action="{{ route('cheese-conversion.undo', $log) }}"
                                              data-confirm-title="Return to farmhouse hold?" data-confirm="Return {{ $log->wheels_converted }} wheel(s) to the farmhouse maturing hold?" data-confirm-label="Return" data-confirm-danger>
                                            @csrf
                                            <button type="submit" class="mf-link" style="color: var(--accent-ink);">Return to farmhouse hold</button>
                                        </form>
                                    @else
                                        <span style="color: var(--faint);">(cut/sold — can't undo)</span>
                                    @endif
                                @endcan
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
