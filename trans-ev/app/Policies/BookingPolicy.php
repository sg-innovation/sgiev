<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\User;
use App\Support\Tenancy;

class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('bookings.view');
    }

    public function view(User $user, Booking $booking): bool
    {
        if ($booking->user_id === $user->id) {
            return true;
        }

        return $user->can('bookings.view') && $this->inScope($user, $booking);
    }

    /** Customers pay for their own bookings while the slot is held. */
    public function pay(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id && $booking->isHoldActive();
    }

    /** Customers cancel their own upcoming bookings. */
    public function cancel(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id && $booking->canBeCancelledByCustomer();
    }

    /** Staff cancellation (e.g. station fault). */
    public function staffCancel(User $user, Booking $booking): bool
    {
        return $user->can('bookings.manage') && $this->inScope($user, $booking);
    }

    /** Staff move a booking to another slot or charger (e.g. charger fault). */
    public function reschedule(User $user, Booking $booking): bool
    {
        return $user->can('bookings.manage') && $this->inScope($user, $booking);
    }

    /** Staff check a customer in at the station (scan, desk or manual). */
    public function checkIn(User $user, Booking $booking): bool
    {
        return $user->can('bookings.manage') && $this->inScope($user, $booking);
    }

    public function create(User $user): bool
    {
        return false; // created only through the booking wizard
    }

    public function update(User $user, Booking $booking): bool
    {
        return false;
    }

    public function delete(User $user, Booking $booking): bool
    {
        return false; // booking records are retained for audit
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function inScope(User $user, Booking $booking): bool
    {
        if (! Tenancy::owns($booking->company_id, $user)) {
            return false;
        }
        if (! $user->hasRole(Role::StationManager->value) || $user->hasAnyRole(Role::companyWide())) {
            return true;
        }

        return $user->stations()->whereKey($booking->station_id)->exists();
    }
}
