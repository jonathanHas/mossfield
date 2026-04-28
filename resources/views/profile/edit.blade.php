<x-app-layout>
    <x-slot name="header">Profile</x-slot>

    <div class="px-6 py-5">
        <div class="mb-4">
            <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Profile</h1>
            <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Your account details, password, and account deletion.</div>
        </div>

        <div class="space-y-4 max-w-3xl">
            <div class="mf-panel p-5">
                @include('profile.partials.update-profile-information-form')
            </div>

            <div class="mf-panel p-5">
                @include('profile.partials.update-password-form')
            </div>

            <div class="mf-panel p-5">
                @include('profile.partials.delete-user-form')
            </div>
        </div>
    </div>
</x-app-layout>
