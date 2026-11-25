<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\NewBookingForOperator;
use App\Support\BusinessRules;
use Illuminate\Support\Collection;

/**
 * Tells a station's staff about new online bookings: company-wide staff who can see bookings, and the station
 * managers assigned to that station. On/off in Business rules; wording and channels in Message templates; each
 * person's SMS / WhatsApp / app choices in their notification settings.
 */
class OperatorAlertService
{
    public function newBooking(Booking $booking): void
    {
        if (BusinessRules::int('booking.operator_alerts') !== 1 || $booking->source === 'walk_in' || ! ($booking->company?->hasFeature('booking_notifications') ?? false)) {
            return;
        }
        $this->recipients($booking)->each(fn (User $u) => $u->notify(new NewBookingForOperator($booking)));
    }

    /** @return Collection<int, User> */
    public function recipients(Booking $booking): Collection
    {
        $max = max(1, BusinessRules::int('booking.operator_alert_max'));

        return User::query()->whereNull('blocked_at')->where('company_id', $booking->company_id)->permission('bookings.view')
            ->with('roles')->get()
            ->filter(function (User $u) use ($booking) {
                if (($u->notification_prefs['operator_bookings'] ?? true) === false) {
                    return false;
                }
                if ($u->hasAnyRole(Role::companyWide())) {
                    return $u->hasAnyRole([Role::CompanyOwner->value, Role::OperationsAdmin->value]) || $u->can('bookings.manage');
                }

                return $u->stations()->whereKey($booking->station_id)->exists();
            })
            ->take($max)->values();
    }
}
