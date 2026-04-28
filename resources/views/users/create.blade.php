<x-app-layout>
    <x-slot name="header">New user</x-slot>

    <div class="px-6 py-5 max-w-3xl mx-auto">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Add user</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Create a new account.</div>
            </div>
            <a href="{{ route('users.index') }}" class="mf-btn-ghost">← All users</a>
        </div>

        <div class="mf-panel">
            <form action="{{ route('users.store') }}" method="POST" class="p-5 space-y-5">
                @csrf
                @include('users.partials.form', ['user' => null, 'roles' => $roles])

                <div class="flex justify-end gap-2 pt-4" style="border-top: 1px solid var(--line-2);">
                    <a href="{{ route('users.index') }}" class="mf-btn-ghost">Cancel</a>
                    <button type="submit" class="mf-btn-primary">Create user</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
