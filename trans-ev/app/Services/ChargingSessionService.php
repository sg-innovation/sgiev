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
            $this->connectors->setLive($connector, ConnectorLiveStatus::Charging, 'staff', 'Session started: '.$booking->code, $by->id);
            activity()->performedOn($booking)->causedBy($by)->log('charging started');

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

        return DB::transaction(function () use ($session, $by, $meterStop, $energy, $reason, $note) {
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
            if ((float) $kwh > $max) {
                throw ValidationException::withMessages([$source === 'meter' ? 'meter_stop' : 'energy' => sprintf('%.3f kWh is more than this %s kW charger can deliver in %d minutes. Check the reading.', $kwh, (float) $s->charger->power_kw, (int) round($hours * 60))]);
            }

            $s->forceFill([
                'status' => 'completed', 'stopped_at' => now(), 'meter_stop_kwh' => $stopReading, 'energy_kwh' => $kwh, 'energy_source' => $source,
                'stopped_by' => $by->id, 'stop_reason' => $reason, 'note' => $note ? mb_substr($note, 0, 255) : null,
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
                $this->connectors->setLive($connector, $reason === 'fault' ? ConnectorLiveStatus::Faulted : ConnectorLiveStatus::Available, 'staff',
                    'Session stopped: '.ChargingSession::STOP_REASONS[$reason], $by->id);
            }

            return $s->fresh();
        });
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
