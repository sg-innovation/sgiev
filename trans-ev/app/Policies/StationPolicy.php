<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Station;
use App\Models\User;
use App\Support\Tenancy;

class StationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stations.view');
    }

    public function view(User $user, Station $station): bool
    {
        return $user->can('stations.view') && $this->inScope($user, $station);
    }

    public function create(User $user): bool
    {
        if (! $user->can('stations.manage')) {
            return false;
        }

        // Partner companies are limited by their plan's station allowance.
        return \App\Support\Tenancy::isPlatformAdmin($user) || ($user->company?->canAddStation() ?? false);
    }

    public function update(User $user, Station $station): bool
    {
        return ($user->can('stations.manage') || $user->can('stations.hours') || $user->can('tariffs.manage')) && $this->inScope($user, $station);
    }

    public function changeStatus(User $user, Station $station): bool
    {
        return $user->can('stations.status') && $this->inScope($user, $station);
    }

    public function delete(User $user, Station $station): bool
    {
        return $user->can('stations.manage') && $this->inScope($user, $station);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('stations.manage');
    }

    public function restore(User $user, Station $station): bool
    {
        return $user->can('stations.manage') && $this->inScope($user, $station);
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('stations.manage');
    }

    /** Stations are only soft-deleted: bookings and invoices reference them for the audit trail. */
    public function forceDelete(User $user, Station $station): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /** Staff are limited to their own company; station managers further to the stations assigned to them. */
    private function inScope(User $user, Station $station): bool
    {
        if (! Tenancy::owns($station->company_id, $user)) {
            return false;
        }
        if (! $user->hasRole(Role::StationManager->value) || $user->hasAnyRole(Role::companyWide())) {
            return true;
        }

        return $user->stations()->whereKey($station->getKey())->exists();
    }
}
