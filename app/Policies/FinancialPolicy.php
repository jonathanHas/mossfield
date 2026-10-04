<?php

namespace App\Policies;

use App\Models\User;

/**
 * Baseline for the finance models (invoices, payments, credit notes,
 * statements, the email log).
 *
 * Narrower than BasePolicy on purpose: canRead() is canWrite(), so FACTORY
 * loses even view access. The factory floor never needs to see what a customer
 * owes — its two carve-outs (OrderPolicy::fulfill and ::load) don't reach here.
 * Admin still passes via BasePolicy::before(); driver stays denied.
 */
abstract class FinancialPolicy extends BasePolicy
{
    protected function canRead(User $user): bool
    {
        return $this->canWrite($user);
    }
}
