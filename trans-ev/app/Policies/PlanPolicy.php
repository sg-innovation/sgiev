<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;
use App\Support\Tenancy;

/** Subscription plans are platform configuration: super admin only. Plans are never deleted (companies reference them). */
class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        return Tenancy::platformCan('plans.manage', $user);
    }

    public function view(User $user, Plan $plan): bool
    {
        return Tenancy::platformCan('plans.manage', $user);
    }

    public function create(User $user): bool
    {
        return Tenancy::platformCan('plans.manage', $user);
    }

    public function update(User $user, Plan $plan): bool
    {
        return Tenancy::platformCan('plans.manage', $user);
    }

    public function delete(User $user, Plan $plan): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
