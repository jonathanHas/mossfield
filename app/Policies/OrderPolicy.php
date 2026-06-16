<?php

namespace App\Policies;

use App\Models\User;

class OrderPolicy extends BasePolicy
{
    /**
     * Picking carve-out: factory may allocate + fulfill (record picks) without
     * gaining general order write access — update/delete stay office-only via
     * BasePolicy::canWrite(). Admin is granted by BasePolicy::before().
     */
    public function fulfill(User $user, mixed $order): bool
    {
        return $user->is_active && ($user->isOffice() || $user->isFactory());
    }

    /**
     * Chilled-run carve-out: factory may tick orders as loaded onto the van
     * (orders.loaded_at) without gaining general order write access. Distinct
     * from fulfill() — loading a crate is not recording a pick.
     */
    public function load(User $user, mixed $order): bool
    {
        return $user->is_active && ($user->isOffice() || $user->isFactory());
    }
}
