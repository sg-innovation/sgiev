<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ChargingMeterValue;
use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use App\Support\BusinessRules;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * C9 live session data, shared by the customer's tracking card and the staff live board.
 * Energy is the latest meter reading or, without one, rated power × utilisation × time (flagged as estimated).
 * Cost so far is the real final-bill calculation (C4) at the energy delivered so far, so it matches what will be billed.
 * Delivered over a JSON endpoint polled every few seconds; the same payload can be broadcast over WebSockets later.
 */
class LiveSessionService
{
    public function __construct(private readonly TrueUpService $trueUp) {}

    public function snapshot(ChargingSession $s, ?Booking $b = null): array
    {
        $tz = Station::TIMEZONE;
        $b ??= $s->booking;
        $m = ChargingMeterValue::query()->where('charging_session_id', $s->id)->orderByDesc('measured_at')->orderByDesc('id')->first();
        $end = $s->stopped_at ?? now();
        $elapsedH = max(0, $s->started_at->diffInSeconds($end, true)) / 3600;
        $util = (float) BusinessRules::get('pricing.power_utilisation');
        $rated = (float) ($s->connector?->powerKw() ?? $s->charger?->power_kw ?? 0);
        $power = $m?->power_kw !== null ? (float) $m->power_kw : $rated * $util;
        $measured = $s->energy_kwh !== null || $m?->energy_kwh !== null;
        $energy = $s->energy_kwh !== null ? (float) $s->energy_kwh : ($m?->energy_kwh !== null ? (float) $m->energy_kwh : round($power * $elapsedH, 2));
        $target = $b && $b->source !== 'walk_in' ? (float) $b->est_kwh : null;

        $finish = null;
        if ($s->status === 'active' && $target && $power > 0) {
            $finish = now()->addSeconds((int) (max(0, $target - $energy) / $power * 3600));
            if ($b?->end_at && $finish->gt($b->end_at)) {
                $finish = $b->end_at->copy();
            }
        }

        $cost = null;
        if ($b) {
            try {
                $cost = (float) ($b->source === 'walk_in'
                    ? app(PricingService::class)->estimate($b->station, $b->charger, '0', 0, 0, max(1, (int) ceil($elapsedH * 60)), $b->start_at, null, number_format($energy, 3, '.', ''))['total_amount']
                    : $this->trueUp->finalBill($b, number_format($energy, 3, '.', ''))['total_amount']);
            } catch (\Throwable $e) {
                Log::warning('Live cost failed', ['booking' => $b->code, 'error' => $e->getMessage()]);
            }
        }

        return [
            'status' => $s->status, 'startedAt' => $s->started_at->timezone($tz)->format('g:i A'), 'startedIso' => $s->started_at->toIso8601String(),
            'minutes' => (int) floor($elapsedH * 60), 'energy' => round($energy, 2), 'measured' => $measured,
            'power' => round($power, 1), 'powerMeasured' => $m?->power_kw !== null, 'soc' => $m?->soc,
            'target' => $target, 'progress' => $target ? min(100, (int) round($energy / max(0.01, $target) * 100)) : null,
            'finishAt' => $finish?->timezone($tz)->format('g:i A'), 'finishIso' => $finish?->toIso8601String(),
            'readingAt' => $m?->measured_at?->timezone($tz)->format('g:i A'),
            'cost' => $cost, 'prepaid' => $b && $b->source !== 'walk_in' ? (float) $b->total_amount : null,
        ];
    }

    /** Payload for the customer's live card. */
    public function forBooking(Booking $b): array
    {
        $s = ChargingSession::query()->where('booking_id', $b->id)->with(['connector', 'charger'])->first();

        return [
            'state' => match (true) {
                $s?->status === 'active' => 'charging',
                $s?->status === 'completed' => 'finished',
                $b->status === BookingStatus::CheckedIn => 'waiting_start',
                default => 'not_started',
            },
            'session' => $s ? $this->snapshot($s, $b->loadMissing(['station', 'charger'])) : null,
            'stopRequestedAt' => $b->stop_requested_at?->timezone(Station::TIMEZONE)->format('g:i A'),
            'canRequestStop' => $s?->status === 'active' && $b->stop_requested_at === null,
            'updatedAt' => now(Station::TIMEZONE)->format('g:i:s A'),
        ];
    }

    /** The customer asks for charging to stop. Manual chargers: staff are alerted in the console. */
    public function requestStop(Booking $b, User $by): void
    {
        if ($b->user_id !== $by->id) {
            throw ValidationException::withMessages(['stop' => 'This is not your booking.']);
        }
        $active = ChargingSession::query()->where('booking_id', $b->id)->where('status', 'active')->first();
        if (! $active) {
            throw ValidationException::withMessages(['stop' => 'Charging is not running.']);
        }
        if ($b->stop_requested_at === null) {
            $b->forceFill(['stop_requested_at' => now()])->save();
            activity()->performedOn($b)->causedBy($by)->log('customer asked to stop charging');
        }
        // OCPP charger online: stop it remotely right away (the final bill follows the charger's meter).
        if ($active->ocpp_transaction_id && $active->charger?->ocpp_connected) {
            app(\App\Services\Ocpp\OcppCommandService::class)->remoteStop($active, $by);
        }
    }
}
