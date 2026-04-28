<x-app-layout>
    <x-slot name="header">Edit {{ $user->name }}</x-slot>

    <div class="px-6 py-5 max-w-3xl mx-auto">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <div class="text-[12px] font-mono" style="color: var(--muted);">User</div>
                <h1 class="mt-0.5 text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Edit {{ $user->name }}</h1>
            </div>
            <a href="{{ route('users.index') }}" class="mf-btn-ghost">← All users</a>
        </div>

        <div class="mf-panel">
            <form action="{{ route('users.update', $user) }}" method="POST" class="p-5 space-y-5">
                @csrf
                @method('PATCH')
                @include('users.partials.form', ['user' => $user, 'roles' => $roles])

                <div class="flex justify-end gap-2 pt-4" style="border-top: 1px solid var(--line-2);">
                    <a href="{{ route('users.index') }}" class="mf-btn-ghost">Cancel</a>
                    <button type="submit" class="mf-btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
