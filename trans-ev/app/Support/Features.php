<?php

namespace App\Support;

use App\Models\User;

/** Plan feature switches (SRS §22). Platform admins and the platform company always have every feature. */
final class Features
{
    public static function enabled(string $feature, ?User $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user) {
            return false;
        }
        if (Tenancy::isPlatformAdmin($user)) {
            return true;
        }

        return $user->company?->hasFeature($feature) ?? false;
    }
}
