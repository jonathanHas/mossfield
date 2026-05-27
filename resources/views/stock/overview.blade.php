<x-app-layout>
    <x-slot name="header">Stock</x-slot>

    <div class="px-6 py-6 max-w-[1400px] mx-auto">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6">
            <div>
                <div class="text-[12px]" style="color: var(--muted);">Production / Stock</div>
                <h1 class="mt-1 text-[28px] font-display font-medium" style="letter-spacing: -0.4px;">Stock overview</h1>
                <div class="mt-1 text-[13px]" style="color: var(--muted);">By product type · with visual fill state</div>
            </div>
            <div class="lg:text-right">
                <div class="text-[11px] font-medium uppercase" style="color: var(--muted); letter-spacing: 0.5px;">
                    Total stock value
                </div>
                <div class="mt-1 text-[32px] font-mono font-semibold" style="color: var(--ink); letter-spacing: -0.6px;">
                    €{{ number_format($total_value, 0) }}
                </div>
            </div>
        </div>

        {{-- Milk card --}}
        @php
            $milkActiveCount = $milk['active_batches']->count();
            $milkVariantCount = $milk['variant_count'] ?? count($milk['variants']);
            $milkSubtitle = trim(
                ($milkVariantCount > 0 ? $milkVariantCount.' '.\Illuminate\Support\Str::plural('variant', $milkVariantCount) : '')
                .($milkActiveCount > 0 ? ' · '.$milkActiveCount.' active '.\Illuminate\Support\Str::plural('batch', $milkActiveCount) : '')
                .($milk['raw_litres'] > 0 ? ' · '.number_format($milk['raw_litres'], 0).'L raw' : ''),
                ' ·'
            );
        @endphp
        <x-stock.card title="Milk" :subtitle="$milkSubtitle" class="mb-4">
            @if($milk['tag'])
                <x-slot name="tag">
                    <x-stock.tag tone="milk">{{ $milk['tag'] }}</x-stock.tag>
                </x-slot>
            @endif
            @if($milkVariantCount === 0)
                <div class="text-[13px] py-2" style="color: var(--muted);">No active milk batches.</div>
            @else
                <div class="flex flex-col gap-5">
                    @foreach($milk['variants'] as $variant)
                        <x-stock.case-blocks
                            :label="$variant['label']"
                            :total="$variant['total']"
                            :case-size="$variant['case_size']"
                            :segments="$variant['segments']"
                            :expiry="$variant['expiry']"
                            :expiry-warn="$variant['expiry_warn']"
                            :batch-code="$variant['batch_code']"
                        />
                    @endforeach
                </div>
            @endif
        </x-stock.card>

        {{-- Yoghurt card --}}
        @php
            $yogActiveCount = $yoghurt['active_batches']->count();
            $yogVariantCount = $yoghurt['variant_count'] ?? count($yoghurt['variants']);
            $yogSubtitle = trim(
                ($yogVariantCount > 0 ? $yogVariantCount.' '.\Illuminate\Support\Str::plural('variant', $yogVariantCount) : '')
                .($yogActiveCount > 0 ? ' · '.$yogActiveCount.' active '.\Illuminate\Support\Str::plural('batch', $yogActiveCount) : ''),
                ' ·'
            );
        @endphp
        <x-stock.card title="Yoghurt" :subtitle="$yogSubtitle" class="mb-4">
            @if($yoghurt['tag'])
                <x-slot name="tag">
                    <x-stock.tag tone="yog">{{ $yoghurt['tag'] }}</x-stock.tag>
                </x-slot>
            @endif
            @if($yogVariantCount === 0)
                <div class="text-[13px] py-2" style="color: var(--muted);">No active yoghurt batches.</div>
            @else
                <div class="flex flex-col gap-5">
                    @foreach($yoghurt['variants'] as $variant)
                        <x-stock.case-pictograph
                            :label="$variant['label']"
                            :total="$variant['total']"
                            :case-size="$variant['case_size']"
                            :segments="$variant['segments']"
                            :expiry="$variant['expiry']"
                            :expiry-warn="$variant['expiry_warn']"
                            :batch-code="$variant['batch_code']"
                        />
                    @endforeach
                </div>
            @endif
        </x-stock.card>

        {{-- Cheese card --}}
        @php
            $cheeseRowCount = count($cheese['wheels'] ?? []) + count($cheese['packs'] ?? []);
            $cheeseSubtitle = $cheeseRowCount > 0
                ? 'Wheel and vacuum-pack stock across active cheese batches'
                : 'No active cheese batches';
        @endphp
        <x-stock.card title="Cheese" :subtitle="$cheeseSubtitle">
            @if($cheese['tag'])
                <x-slot name="tag">
                    <x-stock.tag tone="accent">{{ $cheese['tag'] }}</x-stock.tag>
                </x-slot>
            @endif
            @if($cheeseRowCount === 0)
                <div class="text-[13px] py-2" style="color: var(--muted);">No active cheese batches.</div>
            @else
                <div class="flex flex-col gap-5">
                    @foreach($cheese['wheels'] as $row)
                        <x-stock.cheese-row
                            :label="$row['label'].' (wheels)'"
                            :total="$row['total']"
                            :segments="$row['segments']"
                            :batch-code="$row['batch_code']"
                        />
                    @endforeach
                    @foreach($cheese['packs'] as $row)
                        <x-stock.cheese-row
                            :label="$row['label'].' (packs)'"
                            :total="$row['total']"
                            :segments="$row['segments']"
                            :batch-code="$row['batch_code']"
                        />
                    @endforeach
                </div>
            @endif
        </x-stock.card>
    </div>
</x-app-layout>
