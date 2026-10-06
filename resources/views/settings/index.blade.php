<x-app-layout>
    <x-slot name="header">Settings</x-slot>

    <div class="px-6 py-5">
        <div class="mb-4">
            <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Settings</h1>
            <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Values an admin can change without a deploy. Anything not set here uses the built-in default.</div>
        </div>

        <div class="mf-panel max-w-2xl" data-batch-ceilings>
            <div class="mf-panel-header">
                <div>
                    <div class="text-[13px] font-semibold">Batch quantity ceilings</div>
                    <div class="text-[12.5px]" style="color: var(--muted);">
                        Above these a new batch asks for confirmation before saving. Units per variant per batch.
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('settings.update') }}" class="p-4">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    @foreach($ceilings as $type => $ceiling)
                        <div>
                            <label for="ceiling-{{ $type }}" class="mf-label">{{ ucfirst($type) }}</label>
                            <input type="number" min="1" max="1000000" step="1" required
                                   id="ceiling-{{ $type }}" name="batch_ceilings[{{ $type }}]"
                                   value="{{ old("batch_ceilings.$type", $ceiling['value']) }}"
                                   class="mf-input font-mono">
                            <x-input-error :messages="$errors->get('batch_ceilings.'.$type)" class="mt-1" />
                            <p class="text-[12px] mt-1" style="color: var(--muted);" data-ceiling-meta="{{ $type }}">
                                default {{ number_format($ceiling['default'], 0, '.', ' ') }}
                                @if($ceiling['stored'])
                                    · changed by {{ $ceiling['stored']->updatedBy?->name ?? 'a removed user' }} on {{ $ceiling['stored']->updated_at?->format('d/m/Y') }}
                                @endif
                            </p>
                        </div>
                    @endforeach
                </div>

                <div class="flex items-center justify-end gap-2 pt-4 mt-4" style="border-top: 1px solid var(--line-2);">
                    <button type="submit" class="mf-btn-primary">Save</button>
                </div>
            </form>

            @if($ceilings->contains(fn ($c) => $c['stored']))
                <form method="POST" action="{{ route('settings.update') }}" class="px-4 pb-4 -mt-2 flex justify-end"
                      data-confirm="Reset the batch ceilings to the defaults ({{ $ceilings->map(fn ($c, $t) => $t.' '.number_format($c['default'], 0, '.', ' '))->implode(', ') }})?"
                      data-confirm-label="Reset" data-confirm-danger>
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="reset" value="1">
                    <button type="submit" class="mf-btn-ghost">Reset to defaults</button>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
