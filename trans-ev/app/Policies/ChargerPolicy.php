<?php

namespace App\Policies;

use App\Models\Charger;
use App\Models\User;

/** Chargers are managed with their station: only stations.manage within the station's scope. */
class ChargerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stations.view');
    }

    public function view(User $user, Charger $charger): bool
    {
        return $user->can('view', $charger->station);
    }

    public function create(User $user): bool
    {
        return $user->can('stations.manage');
    }

    public function update(User $user, Charger $charger): bool
    {
        return $user->can('stations.manage') && $user->can('view', $charger->station);
    }

    public function delete(User $user, Charger $charger): bool
    {
        return $this->update($user, $charger);
    }

    public function restore(User $user, Charger $charger): bool
    {
        return $this->update($user, $charger);
    }

    public function forceDelete(User $user, Charger $charger): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
