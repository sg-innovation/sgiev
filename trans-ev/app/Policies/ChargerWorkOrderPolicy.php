<?php

namespace App\Policies;

use App\Models\ChargerWorkOrder;
use App\Models\User;
use App\Services\ChargingSessionService;

/** Work orders: maintenance.view to see, maintenance.manage to open and update, only at stations the user works at. */
class ChargerWorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return ($user->can('maintenance.view') || $user->can('maintenance.manage')) && \App\Support\Features::enabled('maintenance', $user);
    }

    public function view(User $user, ChargerWorkOrder $wo): bool
    {
        return $this->viewAny($user) && ChargingSessionService::worksAt($user, $wo->station_id);
    }

    public function create(User $user): bool
    {
        return $user->can('maintenance.manage') && \App\Support\Features::enabled('maintenance', $user);
    }

    public function update(User $user, ChargerWorkOrder $wo): bool
    {
        return $user->can('maintenance.manage') && ChargingSessionService::worksAt($user, $wo->station_id);
    }

    public function delete(User $user, ChargerWorkOrder $wo): bool
    {
        return false; // work orders are history: cancel instead
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
