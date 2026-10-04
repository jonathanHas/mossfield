<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy extends FinancialPolicy
{
    /** Voiding is only offered while nothing is settled against the invoice. */
    public function void(User $user, Invoice $invoice): bool
    {
        return $this->canWrite($user) && $invoice->isVoidable();
    }

    public function email(User $user, Invoice $invoice): bool
    {
        return $this->canWrite($user) && $invoice->isIssued();
    }

    public function remind(User $user, Invoice $invoice): bool
    {
        return $this->canWrite($user) && $invoice->canBeReminded();
    }
}
