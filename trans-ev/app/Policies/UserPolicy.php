<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use App\Support\Tenancy;

/** Staff management. Company owners manage their own company's staff; platform admins manage everyone. */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.view') && $this->sameCompany($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $model): bool
    {
        if (! $user->can('users.manage') || ! $this->sameCompany($user, $model)) {
            return false;
        }

        // Only platform admins may edit another platform admin.
        return ! $model->hasRole(Role::SuperAdmin->value) || $user->hasRole(Role::SuperAdmin->value);
    }

    /** Block / unblock access. Nobody can block themselves. */
    public function block(User $user, User $model): bool
    {
        return $user->id !== $model->id && $this->update($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        return false; // accounts are blocked, not deleted (audit trail)
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function sameCompany(User $user, User $model): bool
    {
        return Tenancy::isPlatformAdmin($user) || ($model->company_id !== null && $model->company_id === $user->company_id);
    }
}
