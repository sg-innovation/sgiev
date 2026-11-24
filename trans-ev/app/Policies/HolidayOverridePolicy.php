<?php

namespace App\Policies;

use App\Models\HolidayOverride;
use App\Models\User;

/** Holiday / special hours are part of opening hours (stations.hours). */
class HolidayOverridePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stations.view');
    }

    public function view(User $user, HolidayOverride $h): bool
    {
        return $user->can('view', $h->station);
    }

    public function create(User $user): bool
    {
        return $user->can('stations.hours') || $user->can('stations.manage');
    }

    public function update(User $user, HolidayOverride $h): bool
    {
        return $this->create($user) && $user->can('update', $h->station);
    }

    public function delete(User $user, HolidayOverride $h): bool
    {
        return $this->update($user, $h);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
