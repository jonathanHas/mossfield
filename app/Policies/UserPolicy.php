<?php

namespace App\Policies;

use App\Models\User;

/**
 * User management is admin-only. Non-admins are denied on every ability
 * — including viewAny, so the /users index is closed off.
 */
class UserPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        // Deny everything for non-admins / inactive users. Returning false
        // (not null) short-circuits the Gate before any ability method runs.
        return $user->isAdmin() && $user->is_active ? true : false;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, User $target): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $target): bool
    {
        return false;
    }

    public function delete(User $user, User $target): bool
    {
        return false;
    }
}
