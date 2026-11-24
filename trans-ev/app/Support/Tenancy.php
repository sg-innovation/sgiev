<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Company (tenant) scoping for the admin panel.
 *
 * "Platform-wide" users see every company: super admins, and Trans EV (platform company) staff whose role
 * includes the `platform.all_companies` permission. All other staff see only their own company's data.
 * Platform-only permissions (flagged `platform_only` in the permissions table) are checked with platformCan(),
 * which requires Trans EV staff, so a partner company can never gain them through a role.
 * Scoping is applied explicitly in admin queries and policies (not as a global scope), so the public
 * marketplace always shows every company's stations.
 */
final class Tenancy
{
    /** Sees and acts across all companies (subject to the user's other permissions). */
    public static function isPlatformAdmin(?User $user = null): bool
    {
        $user ??= auth()->user();
        if ($user === null) {
            return false;
        }
        if ($user->hasRole(Role::SuperAdmin->value)) {
            return true;
        }

        return $user->isPlatformStaff() && $user->can('platform.all_companies');
    }

    /** A platform-only permission: granted only to Trans EV staff who hold it (super admins hold all). */
    public static function platformCan(string $permission, ?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && $user->isPlatformStaff() && $user->can($permission);
    }

    /** The company the current staff user is limited to, or null for platform-wide users. */
    public static function companyId(?User $user = null): ?int
    {
        $user ??= auth()->user();
        if ($user === null || self::isPlatformAdmin($user)) {
            return null;
        }

        // Staff without a company see nothing rather than everything.
        return $user->company_id ?? -1;
    }

    public static function scope(Builder $query, string $column = 'company_id', ?User $user = null): Builder
    {
        $id = self::companyId($user);

        return $id === null ? $query : $query->where($query->qualifyColumn($column), $id);
    }

    public static function owns(?int $companyId, ?User $user = null): bool
    {
        $id = self::companyId($user);

        return $id === null || $id === $companyId;
    }
}
