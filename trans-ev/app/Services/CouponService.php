<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Charger;
use App\Models\Coupon;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Finds and validates coupon codes for a booking. All failures are customer-friendly validation errors on `coupon_code`. */
class CouponService
{
    /** Find a usable coupon for this station/charger/customer and pre-tax subtotal, or throw. */
    public function resolve(string $code, Station $station, Charger $charger, ?User $user, string $subtotal, bool $lock = false): Coupon
    {
        $code = strtoupper(preg_replace('/\s+/', '', $code));
        $query = Coupon::query()->where('code', $code);
        if ($lock) {
            $query->lockForUpdate(); // serialise redemptions so usage limits hold under concurrency
        }
        $coupon = $query->first();

        $fail = fn (string $msg) => throw ValidationException::withMessages(['coupon_code' => $msg]);

        if (! $coupon || ! $coupon->is_active) {
            $fail('This coupon code is not valid.');
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            $fail('This coupon is not active yet.');
        }
        if ($coupon->ends_at && $coupon->ends_at->isPast()) {
            $fail('This coupon has expired.');
        }
        if ($coupon->company_id !== null && $coupon->company_id !== $station->company_id) {
            $fail('This coupon is not valid at this station.');
        }
        if ($coupon->company_id !== null && ! ($station->company?->hasFeature('coupons') ?? false)) {
            $fail('This coupon is not valid at this station.');
        }
        if (filled($coupon->station_ids) && ! in_array($station->id, array_map('intval', $coupon->station_ids), true)) {
            $fail('This coupon is not valid at this station.');
        }
        if ($coupon->charger_type && $coupon->charger_type !== $charger->type->value) {
            $fail("This coupon is valid only on {$coupon->charger_type} chargers.");
        }
        if ($coupon->min_order !== null && bccomp($subtotal, (string) $coupon->min_order, 2) < 0) {
            $fail('Add more charge to use this coupon (minimum ₹'.rtrim(rtrim((string) $coupon->min_order, '0'), '.').' before GST).');
        }
        if ($coupon->usage_limit !== null && $coupon->redemptions()->count() >= $coupon->usage_limit) {
            $fail('This coupon has been fully used.');
        }
        if ($user) {
            if ($coupon->per_user_limit !== null && $coupon->redemptions()->where('user_id', $user->id)->count() >= $coupon->per_user_limit) {
                $fail('You have already used this coupon.');
            }
            if ($coupon->first_booking_only && $user->bookings()->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed, BookingStatus::NoShow])->exists()) {
                $fail('This coupon is only for your first booking.');
            }
        }

        return $coupon;
    }

    /** Public offers a customer can see on a station's booking page. */
    public function offersFor(Station $station): Collection
    {
        return Coupon::query()->live()->where('is_public', true)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $station->company_id))
            ->orderBy('ends_at')->limit(6)->get()
            ->filter(fn (Coupon $c) => blank($c->station_ids) || in_array($station->id, array_map('intval', $c->station_ids), true))
            ->filter(fn (Coupon $c) => $c->company_id === null || ($station->company?->hasFeature('coupons') ?? false))
            ->filter(fn (Coupon $c) => $c->usage_limit === null || $c->redemptions()->count() < $c->usage_limit)
            ->values();
    }

    public static function present(Coupon $c): array
    {
        return [
            'code' => $c->code, 'name' => $c->name, 'headline' => $c->headline(), 'description' => $c->description,
            'endsAt' => $c->ends_at?->toIso8601String(), 'chargerType' => $c->charger_type, 'firstBookingOnly' => $c->first_booking_only,
        ];
    }

    /** Count of bookings currently using the coupon (for admin stats). */
    public static function usedCount(Coupon $c): int
    {
        return $c->redemptions()->count();
    }

    public static function totalDiscount(Coupon $c): string
    {
        return (string) Booking::query()->where('coupon_id', $c->id)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed, BookingStatus::NoShow])
            ->sum('discount_amount');
    }
}
