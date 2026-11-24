<?php

namespace App\Services;

use App\Enums\StationStatus;
use App\Models\Booking;
use App\Models\Charger;
use App\Models\Connector;
use App\Models\Station;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Single source of truth for whether a charger can be booked at a time:
 * station published & status ∩ opening hours / holidays ∩ maintenance window ∩ charger status ∩ existing bookings.
 */
class AvailabilityService
{



    /** @return list<int> */
    public function durations(Station $station): array
    {
        $allowed = collect($station->allowed_durations ?? [])->map(fn ($m) => (int) $m)->filter(fn ($m) => $m >= 15 && $m <= 720)->unique()->sort()->values();
        if ($allowed->isNotEmpty()) {
            return $allowed->all();
        }

        return collect(range(1, \App\Support\BusinessRules::int('booking.max_slots')))->map(fn ($n) => $n * $station->slot_minutes)->all();
    }

    /** @return list<string> local dates (Y-m-d) customers may book */
    public function bookableDates(): array
    {
        $today = now(Station::TIMEZONE)->startOfDay();

        return collect(range(0, \App\Support\BusinessRules::int('booking.advance_days') - 1))->map(fn ($d) => $today->copy()->addDays($d)->toDateString())->all();
    }

    /**
     * Slot grid for one local date: for each connector, every slot start with its availability.
     *
     * @return array<int, array{charger_id:int, connector_id:int, slots: list<array{start:string, end:string, label:string, available:bool, reason:?string}>}>
     */
    public function grid(Station $station, string $localDate, int $durationMinutes, ?int $ignoreBookingId = null): array
    {
        $station->loadMissing(['chargers.connectorPoints', 'operatingHours', 'holidayOverrides']);
        $date = Carbon::parse($localDate, Station::TIMEZONE)->startOfDay();
        $starts = $this->slotStarts($station, $date, $durationMinutes);

        if ($starts->isEmpty()) {
            return [];
        }

        $dayStart = $starts->first()->copy()->utc();
        $dayEnd = $starts->last()->copy()->addMinutes($durationMinutes + $station->buffer_minutes)->utc();

        $connectors = $station->chargers->flatMap(fn (Charger $c) => $c->connectorPoints->each(fn (Connector $k) => $k->setRelation('charger', $c)));

        $bookings = Booking::query()
            ->whereIn('connector_id', $connectors->pluck('id'))
            ->when($ignoreBookingId, fn ($q) => $q->whereKeyNot($ignoreBookingId))
            ->occupying()
            ->overlapping($dayStart, $dayEnd)
            ->get(['connector_id', 'start_at', 'blocked_until'])
            ->groupBy('connector_id');

        return $connectors->map(function (Connector $connector) use ($station, $starts, $durationMinutes, $bookings) {
            $taken = $bookings->get($connector->id, collect());
            $charger = $connector->charger;

            return [
                'charger_id' => $charger->id,
                'connector_id' => $connector->id,
                'slots' => $starts->map(function (CarbonInterface $start) use ($station, $charger, $connector, $durationMinutes, $taken) {
                    $end = $start->copy()->addMinutes($durationMinutes);
                    $blockedUntil = $end->copy()->addMinutes($station->buffer_minutes);
                    $reason = $this->blockingReason($station, $charger, $start, $end, $connector)
                        ?? ($taken->contains(fn ($b) => $b->start_at->lt($blockedUntil) && $b->blocked_until->gt($start)) ? 'Booked' : null);

                    return [
                        'start' => $start->toIso8601String(),
                        'end' => $end->toIso8601String(),
                        'label' => $start->format('g:i A'),
                        'available' => $reason === null,
                        'reason' => $reason,
                    ];
                })->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Why a charger/connector cannot be booked for [start, end) — ignoring other bookings — or null if it can.
     */
    public function blockingReason(Station $station, Charger $charger, CarbonInterface $start, CarbonInterface $end, ?Connector $connector = null): ?string
    {
        if (! $station->is_published || $station->approval_status !== 'approved' || $station->trashed() || ! ($station->company?->isActive() ?? false)) {
            return 'Unavailable';
        }
        if ($start->lt(now()->addMinutes(\App\Support\BusinessRules::int('booking.min_lead_minutes')))) {
            return 'Past';
        }
        if ($start->gt(now()->addDays(\App\Support\BusinessRules::int('booking.advance_days')))) {
            return 'Too far ahead';
        }
        if ($station->status !== StationStatus::Online) {
            $until = $station->status_until;
            if ($until === null || $start->lt($until)) {
                return $station->status->getLabel();
            }
        }
        if ($charger->trashed() || $charger->status !== StationStatus::Online) {
            return 'Charger '.strtolower($charger->status->getLabel());
        }
        if ($connector && ($connector->trashed() || $connector->status !== StationStatus::Online)) {
            return 'Connector '.strtolower($connector->status->getLabel());
        }
        if ($connector && $connector->live_status->isDown() && $start->lt(now()->addHours(2))) {
            // A faulted/offline connector blocks near-term slots; later slots stay open in case it is repaired.
            return 'Connector '.strtolower($connector->live_status->getLabel());
        }
        if (! $this->withinOpeningHours($station, $start, $end)) {
            return 'Closed';
        }

        return null;
    }

    /** True when $start is exactly one of the station's slot start times for that local day. */
    public function isGridStart(Station $station, CarbonInterface $start, int $durationMinutes): bool
    {
        $local = Carbon::parse($start)->setTimezone(Station::TIMEZONE);
        if ($local->second !== 0 || $local->micro !== 0) {
            return false;
        }

        return $this->slotStarts($station, $local->copy()->startOfDay(), $durationMinutes)
            ->contains(fn (Carbon $s) => $s->equalTo($local));
    }

    public function isConnectorFree(Connector $connector, CarbonInterface $start, CarbonInterface $blockedUntil, ?int $ignoreBookingId = null): bool
    {
        return ! Booking::query()
            ->where('connector_id', $connector->id)
            ->when($ignoreBookingId, fn ($q) => $q->whereKeyNot($ignoreBookingId))
            ->occupying()
            ->overlapping($start, $blockedUntil)
            ->exists();
    }

    private function withinOpeningHours(Station $station, CarbonInterface $start, CarbonInterface $end): bool
    {
        $localStart = Carbon::parse($start)->setTimezone(Station::TIMEZONE);
        $localEnd = Carbon::parse($end)->setTimezone(Station::TIMEZONE);

        foreach ([$localStart->copy()->subDay(), $localStart] as $day) {
            $window = $station->hoursFor($day);
            if ($window && $localStart->gte($window[0]) && $localEnd->lte($window[1])) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, Carbon> slot start times (local tz) for a date */
    private function slotStarts(Station $station, Carbon $date, int $durationMinutes): Collection
    {
        $window = $station->hoursFor($date);
        if (! $window) {
            return collect();
        }
        [$opens, $closes] = $window;
        $step = $station->slot_minutes;
        $starts = collect();

        for ($t = $opens->copy(); $t->copy()->addMinutes($durationMinutes)->lte($closes); $t->addMinutes($step)) {
            $starts->push($t->copy());
        }

        return $starts;
    }
}
