<?php

namespace App\Policies;

use App\Models\User;

/**
 * The communications log is read-only: rows are written by DocumentMailer, never
 * by a user.
 */
class DocumentEmailPolicy extends FinancialPolicy
{
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, mixed $model): bool
    {
        return false;
    }

    public function delete(User $user, mixed $model): bool
    {
        return false;
    }
}
