<?php

namespace App\Policies;

use App\Models\ReconciliationException;
use App\Models\User;
use App\Support\Tenancy;

/** Operators with reconciliation.view see their own company's exceptions; Trans EV staff with reconciliation.manage work the queue. */
class ReconciliationExceptionPolicy
{
    public static function manages(User $user): bool
    {
        return Tenancy::platformCan('reconciliation.manage', $user) && Tenancy::isPlatformAdmin($user);
    }

    public function viewAny(User $user): bool
    {
        return self::manages($user) || $user->can('reconciliation.view');
    }

    public function view(User $user, ReconciliationException $ex): bool
    {
        return self::manages($user) || ($user->can('reconciliation.view') && $ex->company_id !== null && Tenancy::owns($ex->company_id, $user));
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ReconciliationException $ex): bool
    {
        return self::manages($user);
    }

    public function delete(User $user, ReconciliationException $ex): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
