<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Charger;
use App\Models\Connector;
use App\Models\RuleAcceptance;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PricingService $pricing,
        private readonly CouponService $coupons,
    ) {}

    /**
     * Reserve a charger slot (for the hold time set in Business rules) while the customer pays.
     * Double-booking is prevented by locking the charger row and re-checking overlaps inside the transaction.
     *
     * @param  array{name:string, email:string, phone:string, gstin:?string, state_code:?string}  $customer
     * @param  array{make:string, model:string, registration_no:string, battery_kwh:string, connector:string, save:bool}  $vehicle
     */
    public function hold(
        User $user,
        Station $station,
        Charger $charger,
        CarbonInterface $start,
        int $durationMinutes,
        int $batteryFrom,
        int $batteryTo,
        array $customer,
        array $vehicle,
        RuleSet $acceptedRules,
        ?string $ip,
        ?string $userAgent,
        ?Connector $connector = null,
        ?string $couponCode = null,
    ): Booking {
        if ($charger->station_id !== $station->id) {
            throw ValidationException::withMessages(['charger_id' => 'This charger does not belong to the selected station.']);
        }
        if ($connector && $connector->charger_id !== $charger->id) {
            throw ValidationException::withMessages(['connector_id' => 'This connector does not belong to the selected charger.']);
        }
        if (! in_array($durationMinutes, $this->availability->durations($station), true)) {
            throw ValidationException::withMessages(['duration' => 'Please choose a valid booking length.']);
        }
        $connector ??= $charger->connectorPoints()->where('type', $vehicle['connector'])->get()
            ->first(fn (Connector $c) => $c->isBookable()) ?? $charger->connectorPoints()->where('type', $vehicle['connector'])->first();
        if (! $connector || $connector->type->value !== $vehicle['connector']) {
            throw ValidationException::withMessages(['connector' => 'Your vehicle connector is not available on this charger.']);
        }

        if (! ($station->company?->hasFeature('online_booking') ?? false)) {
            throw ValidationException::withMessages(['start' => 'Online booking is not available at this station.']);
        }
        if (! app(\App\Services\Payments\GatewayFactory::class)->canAcceptPayments($station->company)) {
            throw ValidationException::withMessages(['start' => 'Online booking is not available at this station yet (payments not set up by the operator).']);
        }

        $active = RuleSet::activeFor($station);
        if (! $active || $active->id !== $acceptedRules->id) {
            throw ValidationException::withMessages(['rules' => 'The station rules have changed. Please review and accept the latest version.']);
        }

        $end = $start->copy()->addMinutes($durationMinutes);
        $blockedUntil = $end->copy()->addMinutes($station->buffer_minutes);

        if (! $this->availability->isGridStart($station, $start, $durationMinutes)) {
            throw ValidationException::withMessages(['start' => 'Please pick one of the listed start times.']);
        }

        if ($reason = $this->availability->blockingReason($station, $charger, $start, $end, $connector)) {
            throw ValidationException::withMessages(['start' => "This slot can't be booked ({$reason}). Please pick another time."]);
        }

        $estimate = $this->pricing->estimate($station, $charger, $vehicle['battery_kwh'], $batteryFrom, $batteryTo, $durationMinutes, $start);
        $policy = \App\Models\CancellationPolicy::activeFor($station);

        try {
            return $this->reserve(function () use ($user, $station, $charger, $connector, $start, $end, $blockedUntil, $batteryFrom, $batteryTo, $customer, $vehicle, $acceptedRules, $ip, $userAgent, $estimate, $couponCode, $durationMinutes, $policy) {
            // Serialise all bookings for this connector, and this user's holds (hold limit across connectors).
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            Connector::query()->whereKey($connector->id)->lockForUpdate()->first();

            $holds = Booking::query()->where('user_id', $user->id)->where('status', BookingStatus::Held)->where('hold_expires_at', '>', now())->count();
            if ($holds >= \App\Support\BusinessRules::int('booking.max_active_holds')) {
                throw ValidationException::withMessages(['start' => 'You already have bookings waiting for payment. Complete or cancel them first.']);
            }

            if (! $this->availability->isConnectorFree($connector, $start, $blockedUntil)) {
                throw ValidationException::withMessages(['start' => 'Sorry, this slot was just taken. Please pick another time.']);
            }

            // Wallet billing: the operator's prepaid balance must cover this booking's platform fee.
            $platformFee = null;
            if (\App\Services\WalletService::usesWallet($station->company)) {
                $wallets = app(\App\Services\WalletService::class);
                $platformFee = $wallets->feeFor($station->company, $estimate['taxable_amount'])['total'];
                $wallets->assertCanCover($station->company, $platformFee);
            }

            if (filled($couponCode)) {
                // Re-validated under a row lock so usage limits can't be exceeded by parallel checkouts.
                $coupon = $this->coupons->resolve($couponCode, $station, $charger, $user, $estimate['subtotal_amount'], lock: true);
                $estimate = $this->pricing->estimate($station, $charger, $vehicle['battery_kwh'], $batteryFrom, $batteryTo, $durationMinutes, $start, $coupon);
                if ($platformFee !== null) {
                    $platformFee = app(\App\Services\WalletService::class)->feeFor($station->company, $estimate['taxable_amount'])['total'];
                }
            }

            $savedVehicle = null;
            if ($vehicle['save']) {
                $savedVehicle = $user->vehicles()->updateOrCreate(
                    ['registration_no' => $vehicle['registration_no']],
                    collect($vehicle)->only(['make', 'model', 'battery_kwh', 'connector'])->all(),
                );
            }

            $booking = Booking::create([
                'company_id' => $station->company_id,
                'user_id' => $user->id,
                'station_id' => $station->id,
                'charger_id' => $charger->id,
                'connector_id' => $connector->id,
                'vehicle_id' => $savedVehicle?->id,
                'tariff_id' => $estimate['tariff']->id,
                'coupon_id' => $estimate['coupon']?->id,
                'coupon_code' => $estimate['coupon_code'],
                'subtotal_amount' => $estimate['subtotal_amount'],
                'discount_amount' => $estimate['discount_amount'],
                'discount_funded_by' => $estimate['discount_funded_by'],
                'platform_fee' => $platformFee,
                'connector' => $vehicle['connector'],
                'start_at' => $start,
                'end_at' => $end,
                'blocked_until' => $blockedUntil,
                'status' => BookingStatus::Held,
                'hold_expires_at' => now()->addMinutes(\App\Support\BusinessRules::int('booking.hold_minutes')),
                'battery_from' => $batteryFrom,
                'battery_to' => $batteryTo,
                'est_kwh' => $estimate['kwh'],
                'rate_per_kwh' => $estimate['rate_per_kwh'],
                'energy_amount' => $estimate['energy_amount'],
                'session_fee' => $estimate['session_fee'],
                'parking_amount' => $estimate['parking_amount'],
                'price_lines' => $estimate['lines'],
                'taxable_amount' => $estimate['taxable_amount'],
                'gst_rate' => $estimate['gst_rate'],
                'cgst_amount' => $estimate['cgst_amount'],
                'sgst_amount' => $estimate['sgst_amount'],
                'igst_amount' => $estimate['igst_amount'],
                'total_amount' => $estimate['total_amount'],
                'sac_code' => $estimate['sac_code'],
                'place_of_supply' => $estimate['place_of_supply'],
                'supplier_state_code' => $estimate['supplier_state_code'],
                'customer_snapshot' => $customer,
                'vehicle_snapshot' => collect($vehicle)->except('save')->all(),
                'rule_set_id' => $acceptedRules->id,
                // The cancellation policy shown at checkout is what applies to this booking, even if it changes later.
                'cancellation_policy_id' => $policy?->id,
                'cancellation_policy' => $policy?->snapshot(),
            ]);

            RuleAcceptance::create([
                'user_id' => $user->id,
                'booking_id' => $booking->id,
                'rule_set_id' => $acceptedRules->id,
                'version' => $acceptedRules->version,
                'content_hash' => hash('sha256', \App\Support\PublicHtml::clean($acceptedRules->content)), // hash of the text the customer was shown
                'ip' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'accepted_at' => now(),
            ]);

            return $booking;
            });
        } catch (ValidationException $e) {
            app(\App\Services\WalletService::class)->notifyRefused();
            throw $e;
        }
    }

    private function reserve(\Closure $work): Booking
    {
        return DB::transaction($work);
    }

    public function cancel(Booking $booking, string $reason, ?User $by = null): void
    {
        if (! in_array($booking->status, [BookingStatus::Held, BookingStatus::Confirmed], true)) {
            throw ValidationException::withMessages(['booking' => 'This booking can no longer be cancelled.']);
        }

        // Refunds for confirmed (paid) bookings are handled in Phase 3 with the payment gateway.
        $booking->forceFill([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
            'cancelled_by' => $by?->id,
        ])->save();
    }

    /** Scheduled: release holds whose payment window has passed. */
    public function expireHolds(): int
    {
        return Booking::query()
            ->where('status', BookingStatus::Held)
            ->where('hold_expires_at', '<=', now())
            ->get()
            ->each(fn (Booking $b) => $b->forceFill(['status' => BookingStatus::Expired])->save())
            ->count();
    }
}
