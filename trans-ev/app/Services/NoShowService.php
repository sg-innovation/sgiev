<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Station;
use App\Models\User;
use App\Notifications\BookingNotice;
use App\Services\Payments\RefundService;
use App\Support\BusinessRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * C5 no-show automation (scheduled every minute):
 *  - reminders before the slot (minutes set in Business rules, e.g. "60,15,0"; 0 = at the start time), each sent once;
 *  - customers can say they are running late with an ETA: staff see it, and the no-show waits for the ETA, capped by
 *    the extra-grace rule;
 *  - bookings not checked in after the grace are marked no-show automatically: refund per the policy agreed at
 *    checkout, connector released, customer emailed, and a no-show record kept.
 */
class NoShowService
{
    /** Minutes after the start when this booking becomes a no-show (base grace + capped running-late extension). */
    public function graceMinutes(Booking $b): int
    {
        $base = BusinessRules::int('booking.no_show_minutes');
        if (! $b->late_eta_at) {
            return $base;
        }
        $late = (int) ceil($b->start_at->diffInMinutes($b->late_eta_at, false));

        return max($base, min($late + $base, $base + BusinessRules::int('booking.late_extra_minutes')));
    }

    public function noShowAt(Booking $b): Carbon
    {
        return $b->start_at->copy()->addMinutes($this->graceMinutes($b));
    }

    /** @return list<int> reminder offsets in minutes before the start (0 = at the start time) */
    public static function offsets(): array
    {
        return collect(explode(',', (string) BusinessRules::get('reminders.before_minutes')))
            ->map(fn ($v) => trim($v))->filter(fn ($v) => $v !== '' && ctype_digit($v))->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v <= 1440)->unique()->sortDesc()->values()->all();
    }

    public function sendReminders(): int
    {
        $offsets = self::offsets();
        if (! $offsets) {
            return 0;
        }
        $sent = 0;
        Booking::query()->where('status', BookingStatus::Confirmed)->whereNotNull('user_id')
            ->whereBetween('start_at', [now()->subMinutes(2), now()->addMinutes(max($offsets) + 1)])
            ->with(['user', 'station', 'charger'])->get()
            ->each(function (Booking $b) use ($offsets, &$sent) {
                $done = $b->reminders_sent ?? [];
                $minutesLeft = now()->diffInMinutes($b->start_at, false);
                // The smallest offset already reached is the one to send (never send older, larger ones late).
                $due = collect($offsets)->filter(fn ($o) => $minutesLeft <= $o)->min();
                if ($due === null || in_array($due, $done, true)) {
                    return;
                }
                $b->forceFill(['reminders_sent' => array_values(array_unique([...$done, ...array_filter($offsets, fn ($o) => $o >= $due)]))])->save();
                try {
                    $b->user?->notify(new BookingNotice($due === 0 ? 'booking.starting' : 'booking.reminder', $b, [
                        'minutes' => (string) $due, 'no_show_minutes' => (string) $this->graceMinutes($b),
                    ]));
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('Reminder failed', ['booking' => $b->code, 'error' => $e->getMessage()]);
                }
            });

        return $sent;
    }

    /** Customer says they are running late. Returns the message to show them. */
    public function runningLate(Booking $b, User $by, int $minutes, ?string $note = null): string
    {
        if ($b->user_id !== $by->id || $b->status !== BookingStatus::Confirmed) {
            throw ValidationException::withMessages(['minutes' => 'You can only update your own upcoming booking.']);
        }
        if (now()->lt($b->start_at->copy()->subMinutes(60)) || now()->gte($this->noShowAt($b))) {
            throw ValidationException::withMessages(['minutes' => 'You can tell the station you’re running late from an hour before your slot until the grace time ends.']);
        }
        $max = BusinessRules::int('booking.no_show_minutes') + BusinessRules::int('booking.late_extra_minutes');
        $eta = now()->addMinutes(max(1, min($minutes, 240)));
        $b->forceFill(['late_notice_at' => now(), 'late_eta_at' => $eta, 'late_note' => $note ? mb_substr(strip_tags($note), 0, 160) : null])->save();
        activity()->performedOn($b)->causedBy($by)->withProperties(['eta' => $eta->toIso8601String()])->log('customer running late');
        $holdUntil = $this->noShowAt($b->fresh())->timezone(Station::TIMEZONE)->format('g:i A');

        return $eta->gt($b->start_at->copy()->addMinutes($max))
            ? "The station knows you’re late, but can hold your slot only until {$holdUntil}. After that it becomes a no-show."
            : "The station knows you’re on your way. Your slot is held until {$holdUntil}.";
    }

    /** Mark overdue bookings as no-shows (scheduled). */
    public function sweep(): int
    {
        if (BusinessRules::int('booking.auto_no_show') !== 1) {
            return 0;
        }
        $n = 0;
        Booking::query()->where('status', BookingStatus::Confirmed)->where('start_at', '<=', now()->subMinutes(BusinessRules::int('booking.no_show_minutes')))
            ->where('start_at', '>', now()->subDays(2))->get()
            ->filter(fn (Booking $b) => now()->gte($this->noShowAt($b)))
            ->each(function (Booking $b) use (&$n) {
                try {
                    app(RefundService::class)->markNoShow($b, null, 'auto', $this->graceMinutes($b));
                    $n++;
                } catch (\Throwable $e) {
                    Log::warning('Auto no-show failed', ['booking' => $b->code, 'error' => $e->getMessage()]);
                }
            });

        return $n;
    }
}
