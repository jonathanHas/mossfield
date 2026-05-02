@if ($errors->any())
    <div class="mf-flash mf-flash-error">
        <div>
            <ul class="list-disc list-inside text-[12.5px]">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label for="name" class="mf-label">Name</label>
        <input type="text" name="name" id="name" value="{{ old('name', $user?->name) }}" required class="mf-input">
    </div>

    <div>
        <label for="username" class="mf-label">Username</label>
        <input type="text" name="username" id="username" value="{{ old('username', $user?->username) }}" required autocomplete="off" class="mf-input">
        <p class="text-[12px] mt-1" style="color: var(--muted);">Used to log in. Letters, numbers, dashes, underscores.</p>
    </div>

    <div class="md:col-span-2">
        <label for="email" class="mf-label">Email (optional)</label>
        <input type="email" name="email" id="email" value="{{ old('email', $user?->email) }}" autocomplete="off" class="mf-input">
        <p class="text-[12px] mt-1" style="color: var(--muted);">Leave blank for users without a work email. They will only be able to log in via username.</p>
    </div>

    <div>
        <label for="password" class="mf-label">
            {{ $user ? 'New password (leave blank to keep current)' : 'Initial password' }}
        </label>
        <div x-data="{ show: false }" class="relative">
            <input :type="show ? 'text' : 'password'" name="password" id="password"
                   autocomplete="new-password" {{ $user ? '' : 'required' }}
                   class="mf-input pr-16">
            <button type="button" @click="show = !show"
                    class="absolute inset-y-0 right-0 px-3 text-[12px] font-medium"
                    style="color: var(--muted);"
                    x-text="show ? 'Hide' : 'Show'"
                    :aria-label="show ? 'Hide password' : 'Show password'"
                    :aria-pressed="show">
            </button>
        </div>
        <p class="text-[12px] mt-1" style="color: var(--muted);">Minimum 8 characters. Share this with the user in person; they can change it via Profile.</p>
    </div>

    <div>
        <label for="role" class="mf-label">Role</label>
        <select name="role" id="role" required class="mf-select">
            @foreach ($roles as $role)
                <option value="{{ $role->value }}" {{ old('role', $user?->role?->value) === $role->value ? 'selected' : '' }}>
                    {{ $role->label() }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="md:col-span-2">
        <label class="inline-flex items-center">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1"
                   {{ old('is_active', $user?->is_active ?? true) ? 'checked' : '' }} class="mf-checkbox">
            <span class="ml-2 text-[13px]">Active (can log in)</span>
        </label>
    </div>
</div>
