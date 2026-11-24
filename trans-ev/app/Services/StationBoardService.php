<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ChargingMeterValue;
use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\Station;
use App\Support\BusinessRules;
use Illuminate\Support\Carbon;

/**
 * C8 Live Station Board: for each charger and connector, what is happening now and what is next.
 * Energy so far comes from the latest meter reading; without one it is estimated from rated power × utilisation.
 * Finish time and next availability are estimates (labelled so in the UI): delivered vs target energy at the current
 * power, never later than the booked end, plus the station buffer.
 */
class StationBoardService
{
    public function build(Station $station): array
    {
        $tz = Station::TIMEZONE;
        $util = (float) BusinessRules::get('pricing.power_utilisation');
        $buffer = (int) $station->buffer_minutes;
        $station->loadMissing('chargers.connectorPoints');
        $connectorIds = $station->chargers->flatMap->connectorPoints->pluck('id');

        $sessions = ChargingSession::query()->whereIn('connector_id', $connectorIds)->where('status', 'active')->with('booking')->get()->keyBy('connector_id');
        $latest = ChargingMeterValue::query()->whereIn('charging_session_id', $sessions->pluck('id'))->orderBy('measured_at')->get()->groupBy('charging_session_id')->map->last();
        $current = Booking::query()->whereIn('connector_id', $connectorIds)->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Held])
            ->where('start_at', '<=', now())->where('end_at', '>', now())->get()->keyBy('connector_id');
        $upcoming = Booking::query()->whereIn('connector_id', $connectorIds)->occupying()->where('start_at', '>', now())
            ->where('start_at', '<', now($tz)->endOfDay()->utc())->orderBy('start_at')->get()->groupBy('connector_id');

        $counts = ['available' => 0, 'charging' => 0, 'reserved' => 0, 'down' => 0];
        $chargers = $station->chargers->sortBy('code')->map(function ($charger) use ($sessions, $latest, $current, $upcoming, $util, $buffer, $tz, &$counts) {
            return [
                'code' => $charger->code, 'type' => $charger->type->value, 'powerKw' => (float) $charger->power_kw,
                'connectors' => $charger->connectorPoints->map(function (Connector $k) use ($charger, $sessions, $latest, $current, $upcoming, $util, $buffer, $tz, &$counts) {
                    $s = $sessions->get($k->id);
                    $b = $s?->booking ?? $current->get($k->id);
                    $next = $upcoming->get($k->id)?->first(fn (Booking $x) => ! $b || $x->id !== $b->id);
                    $live = $k->live_status;
                    $down = $k->status?->value !== 'online' || $live?->isDown();

                    $session = null;
                    $freeAt = null;
                    if ($s) {
                        $m = $latest->get($s->id);
                        $elapsedH = max(0, $s->started_at->diffInSeconds(now(), true)) / 3600;
                        $power = $m?->power_kw !== null ? (float) $m->power_kw : (float) $k->powerKw() * $util;
                        $measured = $m?->energy_kwh !== null;
                        $energy = $measured ? (float) $m->energy_kwh : round($power * $elapsedH, 2);
                        $target = $b ? (float) $b->est_kwh : null;
                        $finish = null;
                        if ($target && $power > 0) {
                            $finish = now()->addSeconds((int) (max(0, $target - $energy) / $power * 3600));
                            if ($b?->end_at && $finish->gt($b->end_at)) {
                                $finish = $b->end_at->copy();
                            }
                        }
                        $session = [
                            'startedAt' => $s->started_at->timezone($tz)->format('g:i A'), 'minutes' => (int) floor($elapsedH * 60),
                            'energy' => round($energy, 2), 'measured' => $measured, 'power' => round($power, 1), 'powerMeasured' => $m?->power_kw !== null,
                            'soc' => $m?->soc, 'target' => $target, 'progress' => $target ? min(100, (int) round($energy / max(0.01, $target) * 100)) : null,
                            'finishAt' => $finish?->timezone($tz)->format('g:i A'), 'readingAt' => $m?->measured_at?->timezone($tz)->format('g:i A'),
                        ];
                        $freeAt = ($finish ?? $b?->end_at ?? now())->copy()->addMinutes($buffer);
                    } elseif ($b) {
                        $freeAt = $b->end_at->copy()->addMinutes($buffer);
                    }
                    if ($next && $freeAt && $freeAt->gte($next->start_at)) {
                        $freeAt = $next->blocked_until->copy(); // back-to-back: free after the next booking
                    }

                    $state = match (true) {
                        $down => 'down',
                        $s !== null => 'charging',
                        $b !== null => 'reserved',
                        default => 'available',
                    };
                    $counts[$state]++;

                    return [
                        'id' => $k->id, 'no' => $k->connector_no, 'type' => $k->type->getLabel(), 'state' => $state,
                        'live' => $live?->getLabel(), 'tone' => $down ? 'danger' : $live?->getColor(), 'enabled' => $k->status?->value === 'online',
                        'note' => $k->live_note, 'since' => $k->live_status_at?->timezone($tz)->format('g:i A'),
                        'booking' => $b ? [
                            'code' => $b->code, 'customer' => $b->customer_snapshot['name'] ?? null, 'registration' => $b->vehicle_snapshot['registration_no'] ?? null,
                            'vehicle' => trim(($b->vehicle_snapshot['make'] ?? '').' '.($b->vehicle_snapshot['model'] ?? '')), 'source' => $b->source,
                            'slot' => $b->start_at->timezone($tz)->format('g:i A').' – '.$b->end_at->timezone($tz)->format('g:i A'),
                            'status' => $b->status->getLabel(), 'late' => $b->status === BookingStatus::Confirmed && $b->start_at->isPast(),
                        ] : null,
                        'session' => $session,
                        'next' => $next ? ['code' => $next->code, 'at' => $next->start_at->timezone($tz)->format('g:i A'), 'registration' => $next->vehicle_snapshot['registration_no'] ?? null] : null,
                        'freeAt' => $down ? null : ($freeAt ? $freeAt->timezone($tz)->format('g:i A') : 'now'),
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        $dayStart = now($tz)->startOfDay()->utc();

        return [
            'chargers' => $chargers, 'counts' => $counts,
            'today' => [
                'bookings' => Booking::query()->where('station_id', $station->id)->where('start_at', '>=', $dayStart)->where('start_at', '<', $dayStart->copy()->addDay())
                    ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed, BookingStatus::NoShow])->count(),
                'energy' => round((float) ChargingSession::query()->where('station_id', $station->id)->where('status', 'completed')->where('stopped_at', '>=', $dayStart)->sum('energy_kwh'), 1),
                'sessions' => ChargingSession::query()->where('station_id', $station->id)->where('started_at', '>=', $dayStart)->count(),
            ],
            'updatedAt' => now($tz)->format('g:i:s A'),
        ];
    }
}
