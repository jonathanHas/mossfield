<x-app-layout>
    <x-slot name="header">Cut cheese wheel</x-slot>

    <div class="px-6 py-5 max-w-4xl mx-auto">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <div class="text-[12px] font-mono" style="color: var(--muted);">{{ $batchItem->batch->batch_code }} · {{ $batchItem->batch->product->name }}</div>
                <h1 class="mt-0.5 text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Cut cheese wheel</h1>
            </div>
            <a href="{{ route('cheese-cutting.index') }}" class="mf-btn-ghost">← Back to cutting</a>
        </div>

        <div class="mf-panel">
            <div class="mf-panel-header">
                <div class="text-[13px] font-semibold">Wheel to cut</div>
            </div>
            <dl class="px-4 py-3 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-2 text-[13px]">
                <div class="grid grid-cols-[140px_1fr] gap-y-2">
                    <dt style="color: var(--muted);">Product</dt><dd>{{ $batchItem->batch->product->name }}</dd>
                    <dt style="color: var(--muted);">Batch code</dt><dd class="font-mono">{{ $batchItem->batch->batch_code }}</dd>
                    <dt style="color: var(--muted);">Production date</dt><dd class="font-mono">{{ $batchItem->batch->production_date->format('d/m/Y') }}</dd>
                </div>
                <div class="grid grid-cols-[140px_1fr] gap-y-2">
                    <dt style="color: var(--muted);">Variant</dt><dd>{{ $batchItem->productVariant->name }}</dd>
                    <dt style="color: var(--muted);">Wheels available</dt><dd class="font-mono font-semibold">{{ $batchItem->available_quantity }}</dd>
                    <dt style="color: var(--muted);">Weight per wheel</dt><dd class="font-mono">{{ number_format($batchItem->unit_weight_kg, 2) }} kg</dd>
                </div>
            </dl>

            @if($batchItem->available_quantity < 1)
                {{-- Nothing free to cut: show the refusal the controller would give,
                     rather than a form that invites it. --}}
                <div class="px-4 pb-5 pt-4" style="border-top: 1px solid var(--line-2);">
                    <div class="mf-flash mf-flash-warn" style="margin-bottom: 0;">
                        <div>
                            <strong>No free wheels to cut.</strong>
                            The remaining wheels in this batch are allocated to orders or set aside for maturing.
                            Release a hold on the <a href="{{ route('cheese-conversion.index') }}" class="mf-link">Mature Conversion</a> page, or choose another batch.
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-4">
                        <a href="{{ route('cheese-cutting.index') }}" class="mf-btn-secondary">← Back to cutting</a>
                    </div>
                </div>
            @else
            <form method="POST" action="{{ route('cheese-cutting.store', $batchItem) }}" class="px-4 pb-5 pt-2" style="border-top: 1px solid var(--line-2);">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4 mb-4">
                    <div>
                        <x-input-label for="cut_date" :value="__('Cut date')" />
                        <x-text-input id="cut_date" type="date" name="cut_date" :value="old('cut_date', date('Y-m-d'))" required />
                        <x-input-error :messages="$errors->get('cut_date')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="vacuum_pack_variant_id" :value="__('Vacuum pack type')" />
                        <select id="vacuum_pack_variant_id" name="vacuum_pack_variant_id" class="mf-select" required>
                            <option value="">Select vacuum pack type</option>
                            @foreach($vacuumPackVariants as $variant)
                                <option value="{{ $variant->id }}" {{ old('vacuum_pack_variant_id') == $variant->id ? 'selected' : '' }}>
                                    {{ $variant->name }} ({{ number_format($variant->weight_kg, 3) }}kg each)
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('vacuum_pack_variant_id')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <x-input-label for="wheel_weight_kg" :value="__('Wheel weight (kg)')" />
                        <x-text-input id="wheel_weight_kg" type="number" step="0.001" min="0.001" name="wheel_weight_kg" :value="old('wheel_weight_kg')" placeholder="{{ number_format($batchItem->unit_weight_kg, 3) }}" required />
                        <p class="text-[12px] mt-1" style="color: var(--muted);">Weigh the whole wheel before cutting. The packs can't weigh more than this.</p>
                        <x-input-error :messages="$errors->get('wheel_weight_kg')" class="mt-1" />
                    </div>
                    <div class="flex items-end">
                        <div class="text-[12px]" style="color: var(--muted);">
                            Trim / waste: <span id="waste-weight" class="font-semibold font-mono">—</span> kg
                            <span class="block text-[11.5px] mt-0.5">Wheel weight − total pack weight.</span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <x-input-label for="vacuum_packs_created" :value="__('Vacuum packs created')" />
                        <x-text-input id="vacuum_packs_created" type="number" min="1" name="vacuum_packs_created" :value="old('vacuum_packs_created')" required />
                        <p class="text-[12px] mt-1" style="color: var(--muted);">Number of vacuum packs created from this wheel.</p>
                        <x-input-error :messages="$errors->get('vacuum_packs_created')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="total_weight_kg" :value="__('Total weight (kg)')" />
                        <x-text-input id="total_weight_kg" type="number" step="0.001" name="total_weight_kg" :value="old('total_weight_kg')" required />
                        <p class="text-[12px] mt-1" style="color: var(--muted);">Auto-calculated from pack count × unit weight (editable).</p>
                        <x-input-error :messages="$errors->get('total_weight_kg')" class="mt-1" />
                    </div>
                </div>

                <div class="mb-4">
                    <x-input-label for="notes" :value="__('Cutting notes (optional)')" />
                    <textarea id="notes" name="notes" rows="3" class="mf-textarea"
                              placeholder="Any notes about the cutting process, quality, or special observations…">{{ old('notes') }}</textarea>
                    <x-input-error :messages="$errors->get('notes')" class="mt-1" />
                </div>

                <div class="mb-5 mf-flash mf-flash-warn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" />
                    </svg>
                    <div>
                        <strong>Weight calculator.</strong> Average pack weight will be
                        <span id="average-weight" class="font-semibold font-mono">—</span> kg per pack.
                        <span class="block text-[11.5px] mt-0.5" style="color: var(--muted);">Calculated from total weight ÷ pack count.</span>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-4" style="border-top: 1px solid var(--line-2);">
                    <a href="{{ route('cheese-cutting.index') }}" class="mf-btn-ghost">Cancel</a>
                    <x-primary-button>{{ __('Cut wheel') }}</x-primary-button>
                </div>
            </form>
            @endif
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const vacuumPackVariantSelect = document.getElementById('vacuum_pack_variant_id');
            if (! vacuumPackVariantSelect) return; // refusal state: no form on the page
            const vacuumPacksInput = document.getElementById('vacuum_packs_created');
            const totalWeightInput = document.getElementById('total_weight_kg');
            const averageWeightSpan = document.getElementById('average-weight');
            const wheelWeightInput = document.getElementById('wheel_weight_kg');
            const wasteWeightSpan = document.getElementById('waste-weight');

            function updateWaste() {
                const wheel = parseFloat(wheelWeightInput.value) || 0;
                const packs = parseFloat(totalWeightInput.value) || 0;
                if (wheel > 0 && packs > 0) {
                    const waste = wheel - packs;
                    wasteWeightSpan.textContent = waste.toFixed(3);
                    wasteWeightSpan.style.color = waste < 0 ? 'var(--danger)' : '';
                } else {
                    wasteWeightSpan.textContent = '—';
                    wasteWeightSpan.style.color = '';
                }
            }

            function getSelectedVariantWeight() {
                const selectedOption = vacuumPackVariantSelect.options[vacuumPackVariantSelect.selectedIndex];
                if (selectedOption.value) {
                    const match = selectedOption.textContent.match(/\(([0-9.]+)kg each\)/);
                    return match ? parseFloat(match[1]) : 0;
                }
                return 0;
            }

            function updateTotalWeight() {
                const packs = parseInt(vacuumPacksInput.value) || 0;
                const unitWeight = getSelectedVariantWeight();

                if (packs > 0 && unitWeight > 0) {
                    const totalWeight = (packs * unitWeight).toFixed(3);
                    totalWeightInput.value = totalWeight;
                    updateAverageWeight();
                }
            }

            function updateAverageWeight() {
                const packs = parseInt(vacuumPacksInput.value) || 0;
                const totalWeight = parseFloat(totalWeightInput.value) || 0;

                if (packs > 0 && totalWeight > 0) {
                    const average = (totalWeight / packs).toFixed(3);
                    averageWeightSpan.textContent = average;
                } else {
                    averageWeightSpan.textContent = '—';
                }
                updateWaste();
            }

            // The total is a nominal suggestion until the operator types the
            // weighed figure. Once typed it's theirs: a refused submit must
            // hand it back (E2E F-12 — it used to be reset to packs × nominal
            // on load, so a resubmit recorded the nominal, not the weighed).
            // Changing the pack count clears the claim and recomputes.
            let totalTouched = false;

            wheelWeightInput.addEventListener('input', updateWaste);

            vacuumPackVariantSelect.addEventListener('change', function () {
                if (! totalTouched) {
                    updateTotalWeight();
                }
            });
            vacuumPacksInput.addEventListener('input', function () {
                totalTouched = false;
                updateTotalWeight();
            });
            totalWeightInput.addEventListener('input', function () {
                totalTouched = true;
                updateAverageWeight();
            });

            if (totalWeightInput.value) {
                // Restored from old() after a refusal: keep it, refresh the calculator.
                totalTouched = true;
                updateAverageWeight();
            } else if (vacuumPackVariantSelect.value && vacuumPacksInput.value) {
                updateTotalWeight();
            }
        });
    </script>
</x-app-layout>
