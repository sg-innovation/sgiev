<?php

namespace App\Policies;

use App\Models\Tariff;
use App\Models\User;

/** Tariffs are versioned: created and ended, never edited or deleted. */
class TariffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tariffs.view');
    }

    public function view(User $user, Tariff $t): bool
    {
        return $user->can('tariffs.view') && $user->can('view', $t->station);
    }

    public function create(User $user): bool
    {
        return $user->can('tariffs.manage');
    }

    public function update(User $user, Tariff $t): bool
    {
        return false;
    }

    public function end(User $user, Tariff $t): bool
    {
        return $user->can('tariffs.manage') && $user->can('view', $t->station);
    }

    public function delete(User $user, Tariff $t): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
