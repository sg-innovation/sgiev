<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Connector;
use App\Models\RuleSet;
use App\Models\Station;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\BookingNotice;
use App\Support\BusinessRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * C7 waiting list (matched every minute and after cancellations).
 *  - join: day, earliest/latest start, length and vehicle; refused when a slot is already free (book it instead);
 *  - queue number per station/day/connector type, and an estimated wait (when the first compatible connector frees up);
 *  - offer: the first waiting customer whose window fits a free slot gets it as a held booking, with a few minutes to pay
 *    (Business rules). Paid → booked. Not paid → the hold lapses, the entry is "passed on" and the next customer is offered.
 */
class WaitlistService
{
    public function __construct(private readonly AvailabilityService $availability, private readonly BookingService $bookings) {}

    public function join(User $user, Station $station, array $d): WaitlistEntry
    {
        if (BusinessRules::int('waitlist.enabled') !== 1) {
            throw ValidationException::withMessages(['date' => 'The waiting list is not available.']);
        }
        if (! in_array($d['date'], $this->availability->bookableDates(), true)) {
            throw ValidationException::withMessages(['date' => 'Choose one of the bookable days.']);
        }
        if (! in_array((int) $d['duration'], $this->availability->durations($station), true)) {
            throw ValidationException::withMessages(['duration' => 'Choose a valid length.']);
        }
        if ($d['window_from'] >= $d['window_to']) {
            throw ValidationException::withMessages(['window_to' => 'The latest start must be after the earliest start.']);
        }
        if (! Connector::query()->whereHas('charger', fn ($q) => $q->where('station_id', $station->id))->where('type', $d['connector'])->exists()) {
            throw ValidationException::withMessages(['connector' => 'This station has no connector for your vehicle.']);
        }
        $open = WaitlistEntry::query()->where('user_id', $user->id)->whereIn('status', ['waiting', 'offered'])->count();
        if ($open >= BusinessRules::int('waitlist.max_per_user')) {
            throw ValidationException::withMessages(['date' => 'You are already on '.$open.' waiting lists. Leave one to join another.']);
        }
        if (WaitlistEntry::query()->where('user_id', $user->id)->where('station_id', $station->id)->where('date', $d['date'])->whereIn('status', ['waiting', 'offered'])->exists()) {
            throw ValidationException::withMessages(['date' => 'You are already on this station’s waiting list for that day.']);
        }
        $rules = RuleSet::activeFor($station) ?? throw ValidationException::withMessages(['date' => 'This station isn’t taking bookings yet.']);
        $probe = new WaitlistEntry(['station_id' => $station->id, 'date' => $d['date'], 'window_from' => $d['window_from'], 'window_to' => $d['window_to'], 'duration' => (int) $d['duration'], 'connector' => $d['connector']]);
        if ($free = $this->firstFreeSlot($probe, $station)) {
            throw ValidationException::withMessages(['date' => 'A slot is free at '.Carbon::parse($free['start'])->timezone(Station::TIMEZONE)->format('g:i A').'. Book it directly instead of waiting.']);
        }

        $entry = WaitlistEntry::create([
            'company_id' => $station->company_id, 'station_id' => $station->id, 'user_id' => $user->id, 'date' => $d['date'],
            'window_from' => $d['window_from'], 'window_to' => $d['window_to'], 'duration' => (int) $d['duration'], 'connector' => $d['connector'],
            'vehicle' => collect($d)->only(['make', 'model', 'registration_no', 'battery_kwh', 'connector'])->all() + ['battery_kwh' => (string) $d['battery_kwh']],
            'battery_from' => (int) $d['battery_from'], 'battery_to' => (int) $d['battery_to'],
            'customer' => collect($d)->only(['name', 'email', 'phone', 'gstin', 'state_code'])->all(), 'rule_set_id' => $rules->id,
        ]);
        activity()->performedOn($entry)->causedBy($user)->log('joined waiting list');

        return $entry;
    }

    public function leave(WaitlistEntry $e, User $user): void
    {
        abort_unless($e->user_id === $user->id, 403);
        if ($e->status === 'offered' && $e->booking && $e->booking->status === BookingStatus::Held) {
            app(BookingService::class)->cancel($e->booking, 'Left the waiting list', $user);
        }
        if ($e->isOpen()) {
            $e->update(['status' => 'cancelled', 'closed_reason' => 'Left by the customer']);
        }
    }

    /** 1-based place among open entries for the same station, day and connector type. */
    public function position(WaitlistEntry $e): ?int
    {
        if ($e->status !== 'waiting') {
            return null;
        }

        return WaitlistEntry::query()->where('station_id', $e->station_id)->whereDate('date', $e->date)->where('connector', $e->connector)
            ->where('status', 'waiting')->where('id', '<', $e->id)->count() + 1;
    }

    /** Estimated time a compatible connector next frees up within the window (labelled "estimated" in the UI). */
    public function estimate(WaitlistEntry $e): ?Carbon
    {
        [$from, $to] = $this->window($e);
        $ids = $this->connectorIds($e);
        $next = Booking::query()->whereIn('connector_id', $ids)->occupying()->where('blocked_until', '>', max($from, now()))->where('start_at', '<', $to)
            ->orderBy('blocked_until')->value('blocked_until');

        return $next ? Carbon::parse($next) : null;
    }

    /** Offer free slots to waiting customers; settle offers that were paid or lapsed. Returns offers made. */
    public function match(): int
    {
        $this->settleOffers();
        WaitlistEntry::query()->where('status', 'waiting')->get()
            ->filter(fn (WaitlistEntry $e) => $this->window($e)[1]->lte(now()))
            ->each(fn (WaitlistEntry $e) => $e->update(['status' => 'expired', 'closed_reason' => 'The time window ended without a free slot']));

        $made = 0;
        WaitlistEntry::query()->where('status', 'waiting')->with(['station.company', 'user'])->orderBy('id')->get()
            ->each(function (WaitlistEntry $e) use (&$made) {
                if (! $e->user || ! ($slot = $this->firstFreeSlot($e, $e->station))) {
                    return;
                }
                try {
                    $this->offer($e, $slot);
                    $made++;
                } catch (ValidationException $ex) {
                    // e.g. station rules changed, wallet empty, too many holds: leave it waiting and log why.
                    Log::info('Waitlist offer skipped', ['entry' => $e->uuid, 'reason' => collect($ex->errors())->flatten()->first()]);
                } catch (\Throwable $ex) {
                    Log::warning('Waitlist offer failed', ['entry' => $e->uuid, 'error' => $ex->getMessage()]);
                }
            });

        return $made;
    }

    private function offer(WaitlistEntry $e, array $slot): void
    {
        $connector = Connector::query()->with('charger')->findOrFail($slot['connector_id']);
        $rules = RuleSet::activeFor($e->station);
        if (! $rules || $rules->id !== $e->rule_set_id) {
            throw ValidationException::withMessages(['rules' => 'Station rules changed since joining.']);
        }
        $booking = $this->bookings->hold(
            user: $e->user, station: $e->station, charger: $connector->charger, start: Carbon::parse($slot['start'])->utc(), durationMinutes: $e->duration,
            batteryFrom: $e->battery_from, batteryTo: $e->battery_to, customer: $e->customer,
            vehicle: $e->vehicle + ['save' => false], acceptedRules: $rules, ip: null, userAgent: 'waiting list', connector: $connector,
        );
        $expires = now()->addMinutes(BusinessRules::int('waitlist.claim_minutes'));
        $booking->forceFill(['hold_expires_at' => $expires])->save();
        $e->update(['status' => 'offered', 'booking_id' => $booking->id, 'offered_at' => now(), 'offer_expires_at' => $expires, 'offers_made' => $e->offers_made + 1]);
        activity()->performedOn($e)->withProperties(['booking' => $booking->code])->log('waiting list slot offered');
        try {
            $e->user->notify(new BookingNotice('waitlist.offer', $booking, ['minutes' => (string) BusinessRules::int('waitlist.claim_minutes'),
                'expires' => $expires->timezone(Station::TIMEZONE)->format('g:i A')]));
        } catch (\Throwable $ex) {
            Log::warning('Waitlist offer email failed', ['entry' => $e->uuid, 'error' => $ex->getMessage()]);
        }
    }

    private function settleOffers(): void
    {
        WaitlistEntry::query()->where('status', 'offered')->with('booking')->get()->each(function (WaitlistEntry $e) {
            $b = $e->booking;
            if ($b && in_array($b->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed], true)) {
                $e->update(['status' => 'booked']);
            } elseif (! $b || in_array($b->status, [BookingStatus::Expired, BookingStatus::Cancelled], true) || ($b->status === BookingStatus::Held && $b->hold_expires_at?->isPast())) {
                $e->update(['status' => 'passed', 'closed_reason' => 'The offered slot was not paid in time']);
            }
        });
    }

    /** Earliest free slot in the entry's window on a compatible connector, or null. */
    public function firstFreeSlot(WaitlistEntry $e, Station $station): ?array
    {
        [$from, $to] = $this->window($e);
        $earliest = max($from, now()->addMinutes(BusinessRules::int('booking.min_lead_minutes')));
        $ids = $this->connectorIds($e);
        $best = null;
        foreach ($this->availability->grid($station, $e->date->toDateString(), $e->duration) as $row) {
            if (! in_array($row['connector_id'], $ids, true)) {
                continue;
            }
            foreach ($row['slots'] as $s) {
                $start = Carbon::parse($s['start']);
                if ($s['available'] && $start->gte($earliest) && $start->lte($to) && (! $best || $start->lt(Carbon::parse($best['start'])))) {
                    $best = ['start' => $s['start'], 'connector_id' => $row['connector_id']];
                }
            }
        }

        return $best;
    }

    /** @return array{0: Carbon, 1: Carbon} window in UTC */
    private function window(WaitlistEntry $e): array
    {
        $day = ($e->date instanceof Carbon ? $e->date : Carbon::parse($e->date))->format('Y-m-d');

        return [Carbon::parse("{$day} {$e->window_from}", Station::TIMEZONE)->utc(), Carbon::parse("{$day} {$e->window_to}", Station::TIMEZONE)->utc()];
    }

    private function connectorIds(WaitlistEntry $e): array
    {
        return Connector::query()->whereHas('charger', fn ($q) => $q->where('station_id', $e->station_id))->where('type', $e->connector)->pluck('id')->all();
    }
}
