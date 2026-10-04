<?php

namespace App\Policies;

use App\Models\CreditNote;
use App\Models\User;

class CreditNotePolicy extends FinancialPolicy
{
    public function void(User $user, CreditNote $creditNote): bool
    {
        return $this->canWrite($user) && $creditNote->isIssued();
    }
}
