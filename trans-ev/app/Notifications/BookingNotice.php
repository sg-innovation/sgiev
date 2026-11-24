<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Station;
use App\Notifications\Concerns\TemplatedMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Booking messages whose wording lives in Message templates (reminders, no-show, reschedule, waiting list…). */
class BookingNotice extends Notification
{
    use Queueable, TemplatedMessage;

    public function __construct(public string $template, public Booking $booking, public array $vars = []) {}

    public function messageKey(): string
    {
        return $this->template;
    }

    public function messageVars(object $notifiable): array
    {
        $b = $this->booking->loadMissing(['station', 'charger']);
        $tz = Station::TIMEZONE;

        return [
            'name' => $notifiable->name ?? ($b->customer_snapshot['name'] ?? ''),
            'booking_code' => $b->code,
            'station' => $b->station->name,
            'address' => trim("{$b->station->address_line1}, {$b->station->city}"),
            'slot' => $b->start_at->setTimezone($tz)->format('D, j M, g:i A').' – '.$b->end_at->setTimezone($tz)->format('g:i A'),
            'start_time' => $b->start_at->setTimezone($tz)->format('g:i A'),
            'charger' => (string) $b->charger?->code,
        ] + $this->vars;
    }

    public function messageUrl(object $notifiable): ?string
    {
        return route('bookings.show', $this->booking);
    }
}
