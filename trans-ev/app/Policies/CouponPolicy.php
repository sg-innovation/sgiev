<?php

namespace App\Policies;

use App\Models\Coupon;
use App\Models\User;
use App\Support\Features;
use App\Support\Tenancy;

/**
 * Platform admins manage platform-funded and any company's coupons; a company manages only its own,
 * and only when its plan includes the coupons feature. Coupons are deactivated, not deleted (bookings reference them).
 */
class CouponPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('coupons.view') && Features::enabled('coupons', $user);
    }

    public function view(User $user, Coupon $coupon): bool
    {
        return $this->viewAny($user) && $this->owns($user, $coupon);
    }

    public function create(User $user): bool
    {
        return $user->can('coupons.manage') && Features::enabled('coupons', $user);
    }

    public function update(User $user, Coupon $coupon): bool
    {
        return $this->create($user) && $this->owns($user, $coupon);
    }

    public function delete(User $user, Coupon $coupon): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function owns(User $user, Coupon $coupon): bool
    {
        return Tenancy::isPlatformAdmin($user) || ($coupon->company_id !== null && $coupon->company_id === $user->company_id);
    }
}
