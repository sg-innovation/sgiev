<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\ConnectorLiveStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\Station;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual charging sessions (C2) for chargers without OCPP: staff start the session after check-in and stop it with
 * the meter reading (or the energy shown on the charger). The final bill and true-up against the estimate is C4.
 */
class ChargingSessionService
{
    public function __construct(private readonly ConnectorStatusService $connectors, private readonly CheckInService $checkIns) {}

    /** Stations this staff member works at (platform admins: all; station managers: their assigned stations). */
    public static function stations(User $user): Builder
    {
        if (FranchiseService::isRegionalOnly($user)) {
            return Station::query()->whereIn('id', FranchiseService::stationIdsFor($user) ?: [0]);
        }
        $q = Tenancy::scope(Station::query(), 'company_id', $user);
        if ($user->hasRole(Role::StationManager->value) && ! $user->hasAnyRole(Role::companyWide())) {
            $q->whereIn('id', $user->stations()->pluck('stations.id'));
        }

        return $q;
    }

    public static function worksAt(User $user, int $stationId): bool
    {
        return self::stations($user)->whereKey($stationId)->exists();
    }

    public function active(Connector $connector): ?ChargingSession
    {
        return ChargingSession::query()->where('connector_id', $connector->id)->where('status', 'active')->first();
    }

    /** Start charging for a booking (checks the customer in first if needed). */
    public function startForBooking(Booking $booking, User $by, ?string $meterStart = null): ChargingSession
    {
        if (! self::worksAt($by, $booking->station_id) || ! $by->can('sessions.manage')) {
            throw ValidationException::withMessages(['booking' => 'You can’t start sessions at this station.']);
        }
        if ($booking->status === BookingStatus::Confirmed) {
            $this->checkIns->checkIn($booking, 'manual', $by);
            $booking->refresh();
        }
        if ($booking->status !== BookingStatus::CheckedIn) {
            throw ValidationException::withMessages(['booking' => 'Only checked-in bookings can start charging.']);
        }
        if ($booking->end_at->isPast()) {
            throw ValidationException::withMessages(['booking' => 'This slot has ended.']);
        }

        return DB::transaction(function () use ($booking, $by, $meterStart) {
            $connector = Connector::query()->whereKey($booking->connector_id)->lockForUpdate()->firstOrFail();
            if ($existing = ChargingSession::query()->where('booking_id', $booking->id)->first()) {
                return $existing; // already started (double tap)
            }
            if ($connector->live_status?->isDown() || $connector->status?->value !== 'online') {
                throw ValidationException::withMessages(['booking' => 'Connector '.$connector->label().' is not available ('.($connector->live_status?->getLabel() ?? 'offline').').']);
            }
            if ($this->active($connector)) {
                throw ValidationException::withMessages(['booking' => 'Another session is still running on '.$connector->label().'. Stop it first.']);
            }
            $session = ChargingSession::create([
                'company_id' => $booking->company_id, 'station_id' => $booking->station_id, 'charger_id' => $booking->charger_id,
                'connector_id' => $connector->id, 'booking_id' => $booking->id, 'source' => 'manual', 'status' => 'active',
                'started_at' => now(), 'meter_start_kwh' => $this->reading($meterStart, 'meter_start'), 'started_by' => $by->id,
            ]);
            if ($session->meter_start_kwh !== null) {
                $session->meterValues()->create(['measured_at' => now(), 'register_kwh' => $session->meter_start_kwh, 'energy_kwh' => '0', 'context' => 'start', 'recorded_by' => $by->id]);
            }
            $this->connectors->setLive($connector, ConnectorLiveStatus::Charging, 'staff', 'Session started: '.$booking->code, $by->id);
            activity()->performedOn($booking)->causedBy($by)->log('charging started');
            if ($booking->charger?->ocpp_identity && $booking->charger->ocpp_connected) {
                $session->forceFill(['id_tag' => $booking->code])->save();
                app(\App\Services\Ocpp\OcppCommandService::class)->remoteStart($booking, $by);
            }

            return $session;
        });
    }

    /**
     * Stop a session. Energy comes from the meter (stop − start) or, without readings, from the value shown on the
     * charger. Refuses energy the charger could not physically have delivered in the time.
     */
    public function stop(ChargingSession $session, User $by, ?string $meterStop, ?string $energy, string $reason, ?string $note = null): ChargingSession
    {
        if (! self::worksAt($by, $session->station_id) || ! $by->can('sessions.manage')) {
            throw ValidationException::withMessages(['session' => 'You can’t stop sessions at this station.']);
        }
        if (! array_key_exists($reason, ChargingSession::STOP_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose why charging stopped.']);
        }
        // OCPP chargers online: ask the charger to stop; the bill is made from its meter when it reports the stop.
        if ($session->status === 'active' && $session->ocpp_transaction_id && $session->charger?->ocpp_connected && $meterStop === null && $energy === null) {
            app(\App\Services\Ocpp\OcppCommandService::class)->remoteStop($session, $by);

            return $session->fresh();
        }

        return $this->complete($session, $by, $meterStop, $energy, $reason, $note);
    }

    /**
     * Finish a session and bill it (staff stop, or the charger's StopTransaction via OCPP with $by = null).
     * Walk-ins are billed from the energy; prepaid bookings get the final bill / true-up.
     */
    public function complete(ChargingSession $session, ?User $by, ?string $meterStop, ?string $energy, string $reason, ?string $note = null, string $via = 'staff'): ChargingSession
    {
        $stopped = DB::transaction(function () use ($session, $by, $meterStop, $energy, $reason, $note, $via) {
            /** @var ChargingSession $s */
            $s = ChargingSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== 'active') {
                return $s;
            }
            $stopReading = $this->reading($meterStop, 'meter_stop');
            if ($stopReading !== null && $s->meter_start_kwh !== null) {
                if (bccomp($stopReading, (string) $s->meter_start_kwh, 3) < 0) {
                    throw ValidationException::withMessages(['meter_stop' => 'The stop reading is lower than the start reading ('.$s->meter_start_kwh.' kWh).']);
                }
                $kwh = bcsub($stopReading, (string) $s->meter_start_kwh, 3);
                $source = 'meter';
            } else {
                $kwh = $this->reading($energy, 'energy');
                if ($kwh === null) {
                    throw ValidationException::withMessages([$s->meter_start_kwh !== null ? 'meter_stop' : 'energy' => $s->meter_start_kwh !== null
                        ? 'Enter the meter reading now.' : 'Enter the energy delivered (kWh) shown on the charger.']);
                }
                $source = 'entered';
            }
            $hours = max(1, $s->started_at->diffInMinutes(now(), true)) / 60;
            $max = (float) $s->charger->power_kw * $hours * 1.05 + 0.5;
            if ($via !== 'ocpp' && (float) $kwh > $max) {
                throw ValidationException::withMessages([$source === 'meter' ? 'meter_stop' : 'energy' => sprintf('%.3f kWh is more than this %s kW charger can deliver in %d minutes. Check the reading.', $kwh, (float) $s->charger->power_kw, (int) round($hours * 60))]);
            }

            $s->forceFill([
                'status' => 'completed', 'stopped_at' => now(), 'meter_stop_kwh' => $stopReading, 'energy_kwh' => $kwh, 'energy_source' => $source,
                'stopped_by' => $by?->id, 'stop_reason' => $reason, 'note' => $note ? mb_substr($note, 0, 255) : null,
            ])->save();
            if ($s->booking_id && ($walkIn = Booking::query()->whereKey($s->booking_id)->where('source', 'walk_in')->where('status', BookingStatus::CheckedIn)->first())) {
                app(WalkInService::class)->bill($walkIn, $s);
                activity()->performedOn($walkIn)->causedBy($by)->withProperties(['kwh' => $kwh])->log('charging stopped');
            } elseif ($s->booking_id) {
                Booking::query()->whereKey($s->booking_id)->where('status', BookingStatus::CheckedIn)->update(['status' => BookingStatus::Completed->value]);
                activity()->performedOn($s->booking)->causedBy($by)->withProperties(['kwh' => $kwh])->log('charging stopped');
            }
            $connector = Connector::query()->find($s->connector_id);
            if ($connector) {
                $this->connectors->setLive($connector, $reason === 'fault' ? ConnectorLiveStatus::Faulted : ConnectorLiveStatus::Available, $via,
                    'Session stopped: '.ChargingSession::STOP_REASONS[$reason], $by?->id);
            }

            $s->meterValues()->create(['measured_at' => now(), 'register_kwh' => $stopReading, 'energy_kwh' => $kwh, 'context' => 'stop', 'recorded_by' => $by?->id, 'source' => $via === 'ocpp' ? 'ocpp' : 'manual']);

            return $s->fresh();
        });

        // Prepaid bookings: final bill against the energy delivered (refund the difference / balance due).
        if ($stopped->status === 'completed' && $stopped->booking_id && ($booking = Booking::query()->find($stopped->booking_id)) && $booking->source !== 'walk_in') {
            app(TrueUpService::class)->settle($booking, $stopped);
        }

        return $stopped;
    }

    /** An interim reading during the session (meter register, or kWh shown on the charger), with optional power and SoC. */
    public function addReading(ChargingSession $session, User $by, ?string $register, ?string $energy, ?string $power, ?int $soc): void
    {
        if (! self::worksAt($by, $session->station_id) || ! $by->can('sessions.manage')) {
            throw ValidationException::withMessages(['session' => 'You can’t record readings at this station.']);
        }
        if ($session->status !== 'active') {
            throw ValidationException::withMessages(['session' => 'This session has already stopped.']);
        }
        $reg = $this->reading($register, 'register');
        $kwh = $this->reading($energy, 'energy');
        if ($reg !== null && $session->meter_start_kwh !== null) {
            if (bccomp($reg, (string) $session->meter_start_kwh, 3) < 0) {
                throw ValidationException::withMessages(['register' => 'Lower than the start reading ('.$session->meter_start_kwh.' kWh).']);
            }
            $kwh = bcsub($reg, (string) $session->meter_start_kwh, 3);
        }
        if ($reg === null && $kwh === null && $power === null && $soc === null) {
            throw ValidationException::withMessages(['register' => 'Enter at least one reading.']);
        }
        $session->meterValues()->create([
            'measured_at' => now(), 'register_kwh' => $reg, 'energy_kwh' => $kwh, 'power_kw' => $power !== null && $power !== '' ? number_format((float) $power, 2, '.', '') : null,
            'soc' => $soc, 'context' => 'sample', 'recorded_by' => $by->id,
        ]);
    }

    private function reading(?string $value, string $field): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 99999999) {
            throw ValidationException::withMessages([$field => 'Enter a reading in kWh, e.g. 1234.567.']);
        }

        return number_format((float) $value, 3, '.', '');
    }
}
