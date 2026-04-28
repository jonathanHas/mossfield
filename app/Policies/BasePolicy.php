<?php

namespace App\Policies;

use App\Models\User;

/**
 * Baseline for business-data policies (non-User models). Admin gets everything
 * via before(); office gets full CRUD; factory gets view-only; driver denied.
 *
 * Subclass override examples (when needed):
 *   - Drop create/update/delete on read-only models (e.g. audit logs).
 *   - Narrow view() to a scope check (e.g. driver sees only their own route).
 */
abstract class BasePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() && $user->is_active ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->canRead($user);
    }

    public function view(User $user, mixed $model): bool
    {
        return $this->canRead($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, mixed $model): bool
    {
        return $this->canWrite($user);
    }

    public function delete(User $user, mixed $model): bool
    {
        return $this->canWrite($user);
    }

    public function restore(User $user, mixed $model): bool
    {
        return $this->canWrite($user);
    }

    public function forceDelete(User $user, mixed $model): bool
    {
        return $this->canWrite($user);
    }

    protected function canRead(User $user): bool
    {
        return $user->is_active && ($user->isOffice() || $user->isFactory());
    }

    protected function canWrite(User $user): bool
    {
        return $user->is_active && $user->isOffice();
    }
}
