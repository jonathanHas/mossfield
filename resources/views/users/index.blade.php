<x-app-layout>
    <x-slot name="header">Users</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Users</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">User accounts, roles, and access.</div>
            </div>
            <a href="{{ route('users.create') }}" class="mf-btn-primary">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                Add user
            </a>
        </div>

        @if (session('success'))
            <div class="mf-flash mf-flash-success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mf-flash mf-flash-error">{{ session('error') }}</div>
        @endif

        <div class="mf-panel mb-4">
            <form method="GET" action="{{ route('users.index') }}" class="flex flex-wrap gap-3 items-end p-4">
                <div class="flex-1 min-w-[200px]">
                    <label for="search" class="mf-label">Search</label>
                    <input type="text" name="search" id="search" value="{{ request('search') }}"
                           placeholder="Name, username, or email…" class="mf-input">
                </div>
                <div>
                    <label for="role" class="mf-label">Role</label>
                    <select name="role" id="role" class="mf-select">
                        <option value="">All roles</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}" {{ request('role') === $role->value ? 'selected' : '' }}>
                                {{ $role->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="mf-btn-secondary">Filter</button>
                @if(request()->hasAny(['search', 'role']))
                    <a href="{{ route('users.index') }}" class="mf-btn-ghost">Clear</a>
                @endif
            </form>
        </div>

        <div class="mf-panel">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th class="mf-th">Name</th>
                            <th class="mf-th">Username</th>
                            <th class="mf-th">Email</th>
                            <th class="mf-th">Role</th>
                            <th class="mf-th">Status</th>
                            <th class="mf-th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $user)
                            @php
                                $roleTone = match(true) {
                                    $user->isAdmin() => 'danger',
                                    $user->isOffice() => 'info',
                                    $user->isFactory() => 'warn',
                                    $user->isDriver() => 'accent',
                                    default => 'neutral',
                                };
                            @endphp
                            <tr style="border-top: 1px solid var(--line-2);">
                                <td class="mf-td">
                                    <div class="font-medium">{{ $user->name }}</div>
                                    @if ($user->id === auth()->id())
                                        <div class="text-[11.5px] mt-0.5" style="color: var(--muted);">(you)</div>
                                    @endif
                                </td>
                                <td class="mf-td font-mono" style="color: var(--ink-2);">{{ $user->username }}</td>
                                <td class="mf-td" style="color: var(--muted);">{{ $user->email ?: '—' }}</td>
                                <td class="mf-td"><span class="mf-tag mf-tag-{{ $roleTone }}">{{ $user->role->label() }}</span></td>
                                <td class="mf-td">
                                    @if ($user->is_active)
                                        <span class="mf-tag mf-tag-accent">Active</span>
                                    @else
                                        <span class="mf-tag mf-tag-neutral">Inactive</span>
                                    @endif
                                </td>
                                <td class="mf-td text-right whitespace-nowrap">
                                    <a href="{{ route('users.edit', $user) }}" class="mf-link">Edit</a>
                                    @if ($user->id !== auth()->id())
                                        @if ($user->is_active)
                                            <span style="color: var(--faint);"> · </span>
                                            <form action="{{ route('users.deactivate', $user) }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit" class="mf-link" style="color: var(--warn-ink);"
                                                        onclick="return confirm('Deactivate {{ $user->name }}? They will be signed out immediately.')">Deactivate</button>
                                            </form>
                                        @else
                                            <span style="color: var(--faint);"> · </span>
                                            <form action="{{ route('users.reactivate', $user) }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit" class="mf-link" style="color: var(--accent-ink);">Reactivate</button>
                                            </form>
                                        @endif
                                        <span style="color: var(--faint);"> · </span>
                                        <form action="{{ route('users.destroy', $user) }}" method="POST" class="inline">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="mf-link" style="color: var(--danger);"
                                                    onclick="return confirm('Permanently delete {{ $user->name }}? This cannot be undone.')">Delete</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr style="border-top: 1px solid var(--line-2);">
                                <td colspan="6" class="mf-td text-center py-10" style="color: var(--muted);">No users found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($users->hasPages())
                <div class="px-4 py-3" style="border-top: 1px solid var(--line-2);">{{ $users->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
