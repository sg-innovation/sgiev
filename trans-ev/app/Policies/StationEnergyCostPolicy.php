<?php

namespace App\Policies;

use App\Models\StationEnergyCost;
use App\Models\User;
use App\Services\ChargingSessionService;

/** Electricity costs: seen with reports.view, entered with reports.costs, only for stations the user works at. */
class StationEnergyCostPolicy
{
    public function viewAny(User $user): bool
    {
        return ($user->can('reports.view') || $user->can('reports.costs')) && \App\Support\Features::enabled('advanced_reports', $user);
    }

    public function view(User $user, StationEnergyCost $cost): bool
    {
        return $this->viewAny($user) && ChargingSessionService::worksAt($user, $cost->station_id);
    }

    public function create(User $user): bool
    {
        return $user->can('reports.costs') && \App\Support\Features::enabled('advanced_reports', $user);
    }

    public function update(User $user, StationEnergyCost $cost): bool
    {
        return $user->can('reports.costs') && ChargingSessionService::worksAt($user, $cost->station_id);
    }

    public function delete(User $user, StationEnergyCost $cost): bool
    {
        return $this->update($user, $cost);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('reports.costs');
    }
}
