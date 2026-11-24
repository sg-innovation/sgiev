<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingReschedule;
use App\Models\Connector;
use App\Models\Coupon;
use App\Models\Station;
use App\Models\User;
use App\Notifications\BookingNotice;
use App\Support\BusinessRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * C6 reschedule. A paid booking can move to another slot or charger at the same station, for the same length:
 *  - the customer: up to N times, until a set time before the start, and not into a slot that costs more than they paid
 *    (they would cancel and rebook instead); any price difference in their favour is settled by the final bill (C4);
 *  - the operator (bookings.manage for that station): any time before the start, e.g. when a charger breaks.
 * The connector must suit the vehicle. Reminders and running-late notices restart for the new time. Every change is
 * kept in booking_reschedules and the customer is emailed the new time.
 */
class RescheduleService
{
    public function __construct(private readonly AvailabilityService $availability, private readonly PricingService $pricing) {}

    public function customerChanges(Booking $b): int
    {
        return BookingReschedule::query()->where('booking_id', $b->id)->where('by_role', 'customer')->count();
    }

    /** Why the customer can't reschedule now, or null when they can. */
    public function customerBlocker(Booking $b, User $user): ?string
    {
        $notice = BusinessRules::int('reschedule.min_notice_minutes');
        $max = BusinessRules::int('reschedule.max_per_booking');

        return match (true) {
            $b->user_id !== $user->id => 'This is not your booking.',
            $b->status !== BookingStatus::Confirmed => 'Only confirmed bookings can be rescheduled.',
            $max === 0 => 'Rescheduling is not available. You can cancel under the cancellation policy.',
            $this->customerChanges($b) >= $max => "You have already changed this booking {$max} ".($max === 1 ? 'time' : 'times').'. You can still cancel under the cancellation policy.',
            now()->gt($b->start_at->copy()->subMinutes($notice)) => "Bookings can be changed up to {$notice} minutes before the start.",
            default => null,
        };
    }

    public function minutes(Booking $b): int
    {
        return (int) $b->start_at->diffInMinutes($b->end_at, true);
    }

    /** Connectors at the station that suit this booking's vehicle. */
    public function compatibleConnectors(Booking $b)
    {
        return Connector::query()->whereHas('charger', fn ($q) => $q->where('station_id', $b->station_id))
            ->where('type', $b->connector)->with('charger')->get();
    }

    /** Free slots for this booking on a day (its own slot counts as free). */
    public function options(Booking $b, string $localDate): array
    {
        $ids = $this->compatibleConnectors($b)->pluck('id')->all();
        $tz = Station::TIMEZONE;

        return collect($this->availability->grid($b->station, $localDate, $this->minutes($b), $b->id))
            ->filter(fn ($row) => in_array($row['connector_id'], $ids, true))
            ->map(function ($row) use ($b, $tz) {
                $c = Connector::query()->with('charger')->find($row['connector_id']);

                return [
                    'connectorId' => $c->id, 'label' => $c->label(), 'powerKw' => (float) $c->powerKw(),
                    'slots' => collect($row['slots'])->map(fn ($s) => $s + ['current' => $c->id === $b->connector_id && Carbon::parse($s['start'])->eq($b->start_at)])
                        ->filter(fn ($s) => $s['available'])->map(fn ($s) => ['start' => $s['start'], 'label' => Carbon::parse($s['start'])->timezone($tz)->format('g:i A'), 'current' => $s['current']])->values(),
                ];
            })->filter(fn ($r) => $r['slots']->isNotEmpty())->values()->all();
    }

    public function reschedule(Booking $booking, Connector $connector, Carbon $start, User $by, bool $asOperator, ?string $reason = null): Booking
    {
        $booking->loadMissing(['station.company', 'charger']);
        $start = $start->copy()->utc();
        if ($asOperator) {
            if (! $by->can('reschedule', $booking)) {
                throw ValidationException::withMessages(['start' => 'You can’t change bookings at this station.']);
            }
            if ($booking->status !== BookingStatus::Confirmed || $booking->start_at->isPast()) {
                throw ValidationException::withMessages(['start' => 'Only confirmed bookings that have not started can be moved.']);
            }
        } elseif ($why = $this->customerBlocker($booking, $by)) {
            throw ValidationException::withMessages(['start' => $why]);
        }
        $connector->loadMissing('charger');
        $charger = $connector->charger;
        if ($charger->station_id !== $booking->station_id || $connector->type->value !== $booking->connector) {
            throw ValidationException::withMessages(['connector_id' => 'Choose a connector at the same station that suits the vehicle.']);
        }
        $minutes = $this->minutes($booking);
        $station = $booking->station;
        $end = $start->copy()->addMinutes($minutes);
        $blocked = $end->copy()->addMinutes((int) $station->buffer_minutes);
        if ($start->eq($booking->start_at) && $connector->id === $booking->connector_id) {
            throw ValidationException::withMessages(['start' => 'That is the current slot. Pick a different time or charger.']);
        }
        if (! $this->availability->isGridStart($station, $start, $minutes)) {
            throw ValidationException::withMessages(['start' => 'Please pick one of the listed start times.']);
        }
        if ($r = $this->availability->blockingReason($station, $charger, $start, $end, $connector)) {
            throw ValidationException::withMessages(['start' => "That slot isn’t available ({$r})."]);
        }
        if (! $asOperator) {
            $coupon = $booking->coupon_id ? Coupon::query()->find($booking->coupon_id) : null;
            $new = $this->pricing->estimate($station, $charger, (string) ($booking->vehicle_snapshot['battery_kwh'] ?? '0'), (int) $booking->battery_from, (int) $booking->battery_to, $minutes, $start, $coupon);
            if (bccomp(bcsub((string) $new['total_amount'], (string) $booking->total_amount, 2), (string) BusinessRules::get('trueup.min_amount'), 2) > 0) {
                throw ValidationException::withMessages(['start' => 'That time costs more (₹'.number_format((float) $new['total_amount'], 2).' vs ₹'.number_format((float) $booking->total_amount, 2).' paid). Pick another time, or cancel and book again.']);
            }
        }

        $updated = DB::transaction(function () use ($booking, $connector, $charger, $start, $end, $blocked, $by, $asOperator, $reason) {
            Connector::query()->whereKey($connector->id)->lockForUpdate()->first();
            $b = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (! $this->availability->isConnectorFree($connector, $start, $blocked, $b->id)) {
                throw ValidationException::withMessages(['start' => 'Sorry, that slot was just taken. Please pick another.']);
            }
            BookingReschedule::create([
                'booking_id' => $b->id, 'from_start' => $b->start_at, 'from_end' => $b->end_at, 'from_charger_id' => $b->charger_id, 'from_connector_id' => $b->connector_id,
                'to_start' => $start, 'to_end' => $end, 'to_charger_id' => $charger->id, 'to_connector_id' => $connector->id,
                'by_role' => $asOperator ? 'operator' : 'customer', 'changed_by' => $by->id, 'reason' => $reason ? mb_substr(strip_tags($reason), 0, 255) : null,
            ]);
            $b->forceFill([
                'start_at' => $start, 'end_at' => $end, 'blocked_until' => $blocked, 'charger_id' => $charger->id, 'connector_id' => $connector->id,
                'reminders_sent' => null, 'late_notice_at' => null, 'late_eta_at' => null, 'late_note' => null,
            ])->save();
            activity()->performedOn($b)->causedBy($by)->withProperties(['to' => $start->toIso8601String(), 'charger' => $charger->code, 'by' => $asOperator ? 'operator' : 'customer'])->log('rescheduled');

            return $b;
        });

        try {
            $tz = Station::TIMEZONE;
            $updated->user?->notify(new BookingNotice('booking.rescheduled', $updated->fresh(['station', 'charger']), [
                'old_slot' => $booking->start_at->copy()->timezone($tz)->format('D, j M, g:i A'),
                'changed_by' => $asOperator ? 'the station' : 'you', 'reason' => $reason ?: '',
            ]));
        } catch (\Throwable $e) {
            Log::warning('Reschedule email failed', ['booking' => $booking->code, 'error' => $e->getMessage()]);
        }

        return $updated->fresh();
    }
}
