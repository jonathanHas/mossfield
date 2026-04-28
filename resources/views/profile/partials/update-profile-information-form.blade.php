<section>
    <header>
        <h2 class="text-[15px] font-semibold">{{ __('Profile information') }}</h2>
        <p class="mt-1 text-[12.5px]" style="color: var(--muted);">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form method="post" action="{{ route('profile.update') }}" class="mt-5 space-y-4">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-1" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email (optional)')" />
            <x-text-input id="email" name="email" type="email" :value="old('email', $user->email)" autocomplete="email" />
            <x-input-error class="mt-1" :messages="$errors->get('email')" />
        </div>

        <div class="flex items-center gap-3">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-[12.5px]"
                    style="color: var(--accent-ink);"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
