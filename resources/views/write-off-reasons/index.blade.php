<x-app-layout>
    <x-slot name="header">Write-off reasons</x-slot>

    <div class="px-6 py-5 max-w-[900px]">
        <div class="mb-4">
            <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Write-off reasons</h1>
            <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                Why stock was written off. A reason that has been used can be deactivated (it stays on past write-offs) but not deleted.
            </div>
        </div>

        <div class="mf-panel">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th class="mf-th">Reason</th>
                        <th class="mf-th">Code</th>
                        <th class="mf-th text-right">Used</th>
                        <th class="mf-th">Status</th>
                        <th class="mf-th"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reasons as $reason)
                        <tr style="border-top: 1px solid var(--line-2);" id="reason-{{ $reason->id }}">
                            <td class="mf-td">
                                @can('update', $reason)
                                    <form method="POST" action="{{ route('write-off-reasons.update', $reason) }}" class="flex items-center gap-1.5">
                                        @csrf @method('PATCH')
                                        <input type="text" name="label" value="{{ $reason->label }}" maxlength="80" required
                                               class="mf-input" style="padding: 3px 6px; font-size: 12.5px; min-width: 220px;"
                                               aria-label="Rename {{ $reason->label }}">
                                        <button type="submit" class="mf-link text-[12px]">Rename</button>
                                    </form>
                                @else
                                    {{ $reason->label }}
                                @endcan
                            </td>
                            <td class="mf-td font-mono text-[12px]" style="color: var(--muted);">{{ $reason->code }}</td>
                            <td class="mf-td font-mono text-right">{{ $reason->write_offs_count }}</td>
                            <td class="mf-td">
                                <span class="mf-tag mf-tag-{{ $reason->is_active ? 'accent' : 'neutral' }}">{{ $reason->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="mf-td text-right">
                                @can('update', $reason)
                                    <form method="POST" action="{{ route('write-off-reasons.update', $reason) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $reason->is_active ? 0 : 1 }}">
                                        <button type="submit" class="mf-link text-[12px]" style="{{ $reason->is_active ? 'color: var(--danger);' : '' }}">
                                            {{ $reason->is_active ? 'Deactivate' : 'Reactivate' }}
                                        </button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @can('create', App\Models\WriteOffReason::class)
                <form method="POST" action="{{ route('write-off-reasons.store') }}" class="flex items-end gap-2 p-4" style="border-top: 1px solid var(--line);">
                    @csrf
                    <div class="flex-1">
                        <label for="new-reason" class="mf-label">Add reason</label>
                        <input type="text" id="new-reason" name="label" maxlength="80" required class="mf-input" value="{{ old('label') }}" placeholder="e.g. Broken seal">
                        <x-input-error :messages="$errors->get('label')" class="mt-1" />
                    </div>
                    <button type="submit" class="mf-btn-secondary">Add</button>
                </form>
            @endcan
        </div>
    </div>
</x-app-layout>
