<?php

namespace App\Policies;

use App\Models\User;

/**
 * Settings are admin-only, like user management (UserPolicy): every ability
 * is decided in before(), so office and factory are denied even viewAny.
 */
class SettingPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() && $user->is_active ? true : false;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function update(User $user): bool
    {
        return false;
    }
}
