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
                <a href="{{ route('batches.index') }}" class="mf-btn-ghost">← All batches</a>
                @can('update', $batch)
                    <a href="{{ route('batches.edit', $batch) }}" class="mf-btn-secondary">Edit</a>
                @endcan
            </div>
        </div>

        @if (session('success'))
            <div class="mf-flash mf-flash-success">{{ session('success') }}</div>
        @endif

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
                    <dd class="mt-0.5 font-mono">{{ number_format($batch->raw_milk_litres, 2) }} L</dd>
                    @if($batch->wheels_produced && $batch->product->type === 'cheese')
                        <div class="text-[11.5px] mt-0.5" style="color: var(--muted);">{{ $batch->wheels_produced }} wheels produced</div>
                    @endif
                </div>
                @if($batch->finished_product_weight > 0)
                <div>
                    <dt style="color: var(--muted);" class="text-[11.5px] uppercase font-medium" style="letter-spacing: 0.4px;">Finished weight</dt>
                    <dd class="mt-0.5 font-mono">{{ number_format($batch->finished_product_weight, 2) }} kg</dd>
                    @if($batch->production_yield)
                        <div class="text-[11.5px] mt-0.5" style="color: var(--muted);">
                            Yield {{ number_format($batch->production_yield * 100, 1) }}%
                        </div>
                    @endif
                </div>
                @endif
                @if($batch->ready_date)
                <div>
                    <dt style="color: var(--muted);" class="text-[11.5px] uppercase font-medium" style="letter-spacing: 0.4px;">Ready date</dt>
                    <dd class="mt-0.5 font-mono">{{ $batch->ready_date->format('d/m/Y') }}</dd>
                    @if(!$batch->isReadyToSell())
                        <div class="text-[11.5px] mt-0.5" style="color: var(--warn-ink);">{{ $batch->ready_date->diffForHumans() }}</div>
                    @else
                        <div class="text-[11.5px] mt-0.5" style="color: var(--accent-ink);">Ready now</div>
                    @endif
                </div>
                @endif
                @if($batch->expiry_date)
                <div>
                    <dt style="color: var(--muted);" class="text-[11.5px] uppercase font-medium" style="letter-spacing: 0.4px;">Expiry date</dt>
                    <dd class="mt-0.5 font-mono">{{ $batch->expiry_date->format('d/m/Y') }}</dd>
                    @if($batch->isExpired())
                        <div class="text-[11.5px] mt-0.5" style="color: var(--danger);">Expired</div>
                    @endif
                </div>
                @endif
                <div>
                    <dt style="color: var(--muted);" class="text-[11.5px] uppercase font-medium" style="letter-spacing: 0.4px;">Stock remaining</dt>
                    <dd class="mt-0.5 font-mono font-semibold text-[15px]" style="color: {{ $batch->remaining_stock > 0 ? 'var(--accent-ink)' : 'var(--danger)' }};">
                        {{ $batch->remaining_stock }} units
                    </dd>
                </div>
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
                                <th class="mf-th text-right">Sold</th>
                                <th class="mf-th text-right">Remaining</th>
                                <th class="mf-th text-right">Total weight</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($batch->batchItems as $item)
                                <tr style="border-top: 1px solid var(--line-2);">
                                    <td class="mf-td font-medium">{{ $item->productVariant->name }}</td>
                                    <td class="mf-td" style="color: var(--muted);">{{ $item->productVariant->size }} {{ $item->productVariant->unit }}</td>
                                    <td class="mf-td font-mono" style="color: var(--muted);">{{ $item->unit_weight_kg ? number_format($item->unit_weight_kg, 3) . ' kg' : '—' }}</td>
                                    <td class="mf-td font-mono text-right">{{ number_format($item->quantity_produced) }}</td>
                                    <td class="mf-td font-mono text-right" style="color: var(--muted);">{{ number_format($item->quantity_sold) }}</td>
                                    <td class="mf-td font-mono text-right font-medium" style="color: {{ $item->quantity_remaining > 0 ? 'var(--accent-ink)' : 'var(--danger)' }};">
                                        {{ number_format($item->quantity_remaining) }}
                                    </td>
                                    <td class="mf-td font-mono text-right" style="color: var(--muted);">{{ $item->unit_weight_kg ? number_format($item->total_weight, 2) . ' kg' : '—' }}</td>
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
                    <div class="text-[13px] font-semibold">Cheese cutting history</div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th class="mf-th">Cut date</th>
                                <th class="mf-th">Source wheel</th>
                                <th class="mf-th text-right">Vac packs</th>
                                <th class="mf-th text-right">Total weight</th>
                                <th class="mf-th text-right">Avg pack</th>
                                <th class="mf-th">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($batch->cuttingLogs as $log)
                                <tr style="border-top: 1px solid var(--line-2);">
                                    <td class="mf-td font-mono">{{ $log->cut_date->format('d/m/Y') }}</td>
                                    <td class="mf-td">{{ $log->sourceBatchItem->productVariant->name }}</td>
                                    <td class="mf-td font-mono text-right">{{ number_format($log->vacuum_packs_created) }}</td>
                                    <td class="mf-td font-mono text-right">{{ number_format($log->total_weight_kg, 2) }} kg</td>
                                    <td class="mf-td font-mono text-right">{{ number_format($log->average_pack_weight, 3) }} kg</td>
                                    <td class="mf-td" style="color: var(--muted);">{{ $log->notes ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="mf-panel">
            <div class="mf-panel-header">
                <div class="text-[13px] font-semibold">Actions</div>
            </div>
            <div class="px-4 py-3">
                <div class="flex flex-wrap gap-2">
                    @if($batch->product->type === 'cheese')
                        @php
                            $availableWheels = $batch->batchItems->filter(function($item) {
                                return str_contains(strtolower($item->productVariant->name), 'wheel') && $item->quantity_remaining > 0;
                            });
                            $isReady = $batch->ready_date === null || $batch->ready_date <= now();
                        @endphp
                        @if($availableWheels->count() > 0 && $isReady)
                            @can('create', App\Models\CheeseCuttingLog::class)
                                @foreach($availableWheels as $wheelItem)
                                    <a href="{{ route('cheese-cutting.create', $wheelItem) }}" class="mf-btn-primary">
                                        Cut {{ $wheelItem->productVariant->name }}
                                        <span class="text-[11.5px] font-mono ml-1" style="opacity: 0.8;">({{ $wheelItem->quantity_remaining }} left)</span>
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
                    @endif
                </div>
                <p class="text-[12px] mt-3" style="color: var(--muted);">More actions coming soon.</p>
            </div>
        </div>
    </div>
</x-app-layout>
