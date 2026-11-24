<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ChargingSession;
use App\Models\CheckInRecord;
use App\Models\Connector;
use App\Models\Payment;
use App\Models\RuleSet;
use App\Models\User;
use App\Support\BusinessRules;
use App\Support\Gstin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * C3 walk-ins. A driver without a booking: staff enter the vehicle, pick a free connector and start charging. The
 * connector is blocked for online bookings while in use (the walk-in is a booking with source "walk_in"). When charging
 * stops the bill is made from the energy actually delivered, then paid at the station: cash, UPI (with the reference),
 * or an online payment link the driver opens by scanning a QR. The GST invoice is issued once paid.
 */
class WalkInService
{
    /** Offline payment methods recorded by staff. */
    public const OFFLINE = ['cash' => 'Cash', 'upi_manual' => 'UPI (to the station)'];

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PricingService $pricing,
        private readonly ChargingSessionService $sessions,
    ) {}

    public static function canManage(User $user, int $stationId): bool
    {
        return $user->can('walkins.manage') && ChargingSessionService::worksAt($user, $stationId);
    }

    /** When the next online booking needs this connector (null = free for the rest of the day). */
    public function freeUntil(Connector $connector): ?Carbon
    {
        $next = Booking::query()->where('connector_id', $connector->id)->occupying()->where('blocked_until', '>', now())
            ->orderBy('start_at')->value('start_at');

        return $next ? Carbon::parse($next) : null;
    }

    /** Longest walk-in that fits before the next booking (minus the station buffer), capped by the business rule. */
    public function maxMinutes(Connector $connector): int
    {
        $cap = BusinessRules::int('walkin.max_minutes');
        $until = $this->freeUntil($connector);
        if (! $until) {
            return $cap;
        }
        $buffer = (int) ($connector->charger->station->buffer_minutes ?? 0);

        return max(0, min($cap, (int) floor(now()->diffInMinutes($until, false)) - $buffer));
    }

    /**
     * @param  array{registration_no: string, make?: ?string, model?: ?string, name: string, phone: string, email?: ?string, gstin?: ?string, minutes: int, meter_start?: ?string}  $d
     */
    public function start(Connector $connector, array $d, User $by): Booking
    {
        $connector->loadMissing('charger.station.company');
        $charger = $connector->charger;
        $station = $charger->station;
        if (! self::canManage($by, $station->id) || ! $by->can('sessions.manage')) {
            throw ValidationException::withMessages(['connector_id' => 'You can’t start walk-ins at this station.']);
        }
        if ($connector->status?->value !== 'online' || $connector->live_status?->isDown() || $connector->live_status?->isBusy()) {
            throw ValidationException::withMessages(['connector_id' => $connector->label().' is not free right now ('.($connector->live_status?->getLabel() ?? 'offline').').']);
        }
        $minutes = (int) $d['minutes'];
        $max = $this->maxMinutes($connector);
        if ($minutes < 10) {
            throw ValidationException::withMessages(['minutes' => 'Choose at least 10 minutes.']);
        }
        if ($minutes > $max) {
            throw ValidationException::withMessages(['minutes' => $max < 10
                ? 'This connector is booked online soon. Use another connector.'
                : "This connector is free for {$max} minutes before the next online booking."]);
        }
        $rules = RuleSet::activeFor($station) ?? throw ValidationException::withMessages(['connector_id' => 'Publish the station rules first.']);
        $gstin = Gstin::normalise($d['gstin'] ?? null);
        if ($gstin && ! Gstin::valid($gstin)) {
            throw ValidationException::withMessages(['gstin' => 'This GSTIN doesn’t look right. Please check it.']);
        }

        $start = now();
        $end = $start->copy()->addMinutes($minutes);
        $blocked = $end->copy()->addMinutes((int) $station->buffer_minutes);
        $expected = GstService::round(bcmul(bcmul((string) $charger->power_kw, (string) BusinessRules::get('pricing.power_utilisation'), 6), bcdiv((string) $minutes, '60', 6), 6), 3);
        $estimate = $this->pricing->estimate($station, $charger, '0', 0, 0, $minutes, $start, null, $expected);

        $booking = DB::transaction(function () use ($connector, $charger, $station, $d, $by, $minutes, $start, $end, $blocked, $estimate, $rules, $gstin) {
            Connector::query()->whereKey($connector->id)->lockForUpdate()->first();
            if (! $this->availability->isConnectorFree($connector, $start, $blocked)) {
                throw ValidationException::withMessages(['minutes' => 'An online booking needs this connector in that time. Choose fewer minutes or another connector.']);
            }
            $booking = Booking::create([
                'company_id' => $station->company_id, 'user_id' => null, 'source' => 'walk_in', 'created_by' => $by->id,
                'station_id' => $station->id, 'charger_id' => $charger->id, 'connector_id' => $connector->id, 'tariff_id' => $estimate['tariff']->id,
                'connector' => $connector->type->value, 'start_at' => $start, 'end_at' => $end, 'blocked_until' => $blocked,
                'status' => BookingStatus::CheckedIn, 'settlement' => 'due', 'confirmed_at' => $start, 'checked_in_at' => $start,
                'battery_from' => 0, 'battery_to' => 0,
                'est_kwh' => $estimate['kwh'], 'rate_per_kwh' => $estimate['rate_per_kwh'], 'energy_amount' => $estimate['energy_amount'],
                'session_fee' => $estimate['session_fee'], 'parking_amount' => $estimate['parking_amount'], 'price_lines' => $estimate['lines'],
                'subtotal_amount' => $estimate['subtotal_amount'], 'discount_amount' => '0.00',
                'taxable_amount' => $estimate['taxable_amount'], 'gst_rate' => $estimate['gst_rate'], 'cgst_amount' => $estimate['cgst_amount'],
                'sgst_amount' => $estimate['sgst_amount'], 'igst_amount' => $estimate['igst_amount'], 'total_amount' => $estimate['total_amount'],
                'sac_code' => $estimate['sac_code'], 'place_of_supply' => $estimate['place_of_supply'], 'supplier_state_code' => $estimate['supplier_state_code'],
                'customer_snapshot' => [
                    'name' => trim($d['name']), 'phone' => trim($d['phone']), 'email' => filled($d['email'] ?? null) ? strtolower(trim($d['email'])) : null,
                    'gstin' => $gstin, 'state_code' => $gstin ? substr($gstin, 0, 2) : $station->state_code, 'walk_in' => true,
                ],
                'vehicle_snapshot' => [
                    'registration_no' => strtoupper(preg_replace('/\s+/', '', $d['registration_no'])), 'make' => $d['make'] ?? null, 'model' => $d['model'] ?? null,
                    'connector' => $connector->type->value,
                ],
                'rule_set_id' => $rules->id,
            ]);
            CheckInRecord::create([
                'booking_id' => $booking->id, 'company_id' => $booking->company_id, 'station_id' => $station->id, 'charger_id' => $charger->id,
                'connector_id' => $connector->id, 'method' => 'walk_in', 'checked_in_by' => $by->id, 'checked_in_at' => $start, 'late_minutes' => 0,
                'ip' => request()?->ip(), 'user_agent' => mb_substr((string) request()?->userAgent(), 0, 255) ?: null,
            ]);
            activity()->performedOn($booking)->causedBy($by)->log('walk-in started');

            return $booking;
        });

        $this->sessions->startForBooking($booking, $by, filled($d['meter_start'] ?? null) ? (string) $d['meter_start'] : null);

        return $booking->fresh();
    }

    /** Called when a walk-in's session stops: bill the energy actually delivered and free the connector. */
    public function bill(Booking $booking, ChargingSession $session): void
    {
        $booking->loadMissing(['station.company', 'charger']);
        $minutes = max(1, $session->minutes());
        $e = $this->pricing->estimate($booking->station, $booking->charger, '0', 0, 0, $minutes, $booking->start_at, null, (string) $session->energy_kwh);
        $fee = null;
        if (BusinessRules::int('platform_fees.walk_ins') === 1 && WalletService::usesWallet($booking->station->company)) {
            $fee = app(WalletService::class)->feeFor($booking->station->company, $e['taxable_amount'])['total'];
        }
        $booking->forceFill([
            'status' => BookingStatus::Completed, 'settlement' => 'due', 'end_at' => now(), 'blocked_until' => now(),
            'est_kwh' => $e['kwh'], 'rate_per_kwh' => $e['rate_per_kwh'], 'energy_amount' => $e['energy_amount'], 'session_fee' => $e['session_fee'],
            'parking_amount' => $e['parking_amount'], 'price_lines' => $e['lines'], 'subtotal_amount' => $e['subtotal_amount'],
            'taxable_amount' => $e['taxable_amount'], 'gst_rate' => $e['gst_rate'], 'cgst_amount' => $e['cgst_amount'], 'sgst_amount' => $e['sgst_amount'],
            'igst_amount' => $e['igst_amount'], 'total_amount' => $e['total_amount'], 'platform_fee' => $fee,
        ])->save();
    }

    /** Staff record cash or UPI received at the station. */
    public function collect(Booking $booking, string $method, ?string $reference, User $by): Payment
    {
        if (! array_key_exists($method, self::OFFLINE)) {
            throw ValidationException::withMessages(['method' => 'Choose how the customer paid.']);
        }
        if (! self::canManage($by, $booking->station_id)) {
            throw ValidationException::withMessages(['method' => 'You can’t take payments at this station.']);
        }
        if ($method === 'upi_manual' && ! preg_match('/^[A-Za-z0-9]{6,30}$/', (string) $reference)) {
            throw ValidationException::withMessages(['reference' => 'Enter the UPI reference (UTR) shown in the customer’s app.']);
        }

        $payment = DB::transaction(function () use ($booking, $method, $reference, $by) {
            $b = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertDue($b);
            $payment = Payment::create([
                'company_id' => $b->company_id, 'booking_id' => $b->id, 'user_id' => null, 'collected_by' => $by->id, 'purpose' => 'booking',
                'gateway' => $method, 'environment' => 'offline', 'merchant_txn_id' => strtoupper($method === 'cash' ? 'CASH' : 'UPI').'-'.now()->format('ymdHis').Str::upper(Str::random(5)),
                'amount' => $b->total_amount, 'paid_amount' => $b->total_amount, 'status' => Payment::SUCCESS, 'gateway_status' => 'SUCCESS',
                'payment_mode' => self::OFFLINE[$method], 'bank_rrn' => $reference ? strtoupper($reference) : null, 'paid_at' => now(), 'verified_via' => 'staff',
            ]);
            $this->markPaid($b, $payment, $by);

            return $payment;
        });

        return $payment;
    }

    /** Online payment link for the driver to scan and pay on their own phone. Returns the checkout URL. */
    public function paymentLink(Booking $booking, User $by): string
    {
        if (! self::canManage($by, $booking->station_id)) {
            throw ValidationException::withMessages(['method' => 'You can’t take payments at this station.']);
        }
        $this->assertDue($booking);

        return app(Payments\PaymentService::class)->startWalkIn($booking);
    }

    /** Online payment for a walk-in succeeded (called from PaymentService inside its transaction). */
    public function settleOnline(Booking $booking, Payment $payment): bool
    {
        $amountOk = bccomp((string) ($payment->paid_amount ?? $payment->amount), (string) $booking->total_amount, 2) === 0;
        if ($booking->settlement !== 'due' || ! $amountOk) {
            return false;
        }
        $this->markPaid($booking, $payment, null);

        return true;
    }

    private function assertDue(Booking $b): void
    {
        if ($b->source !== 'walk_in' || $b->settlement !== 'due' || $b->status !== BookingStatus::Completed) {
            throw ValidationException::withMessages(['method' => $b->settlement === 'paid' ? 'This walk-in is already paid.' : 'Stop charging first; the bill is made from the energy delivered.']);
        }
    }

    private function markPaid(Booking $b, Payment $payment, ?User $by): void
    {
        $b->forceFill(['settlement' => 'paid'])->save();
        activity()->performedOn($b)->causedBy($by)->withProperties(['method' => $payment->gateway, 'amount' => (string) $payment->amount])->log('walk-in paid');
        app(WalletService::class)->chargeBooking($b->fresh('company'), $payment);
        DB::afterCommit(function () use ($b, $payment) {
            try {
                app(InvoiceService::class)->issueForBooking($b->fresh(), $payment->fresh());
            } catch (\Throwable $e) {
                Log::error('Walk-in invoice failed', ['booking' => $b->code, 'error' => $e->getMessage()]);
            }
        });
    }
}
