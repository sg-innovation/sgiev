<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('company.view');
    }

    public function view(User $user, Company $company): bool
    {
        return $user->can('company.view') && Tenancy::owns($company->id, $user);
    }

    /** Onboarding new companies is a platform action. */
    public function create(User $user): bool
    {
        return Tenancy::platformCan('partners.review', $user);
    }

    public function update(User $user, Company $company): bool
    {
        return $user->can('company.manage') && Tenancy::owns($company->id, $user);
    }

    /** Suspend / reactivate a company (platform only; the platform company itself cannot be suspended). */
    public function suspend(User $user, Company $company): bool
    {
        return Tenancy::platformCan('partners.review', $user) && ! $company->is_platform;
    }

    public function delete(User $user, Company $company): bool
    {
        return false; // companies are suspended, never deleted (bookings and invoices reference them)
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
