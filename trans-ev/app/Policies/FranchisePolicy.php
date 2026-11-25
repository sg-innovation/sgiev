<?php

namespace App\Policies;

use App\Models\Franchise;
use App\Models\User;
use App\Support\Tenancy;

/** Franchises are managed by Trans EV (franchises.manage); regional managers can view their own. */
class FranchisePolicy
{
    public function viewAny(User $user): bool
    {
        return Tenancy::platformCan('franchises.manage', $user) || $user->can('franchises.view');
    }

    public function view(User $user, Franchise $f): bool
    {
        return Tenancy::platformCan('franchises.manage', $user) || ($user->can('franchises.view') && $f->managers()->whereKey($user->id)->exists());
    }

    public function create(User $user): bool
    {
        return Tenancy::platformCan('franchises.manage', $user);
    }

    public function update(User $user, Franchise $f): bool
    {
        // Regional managers open the same page read-only (the form is disabled and has no save button for them).
        return $this->view($user, $f);
    }

    public function delete(User $user, Franchise $f): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
