<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Station;
use App\Support\Messages;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Booking emails whose wording lives in Message templates (reminders, no-show, running-late acknowledgement…). */
class BookingNotice extends Notification
{
    use Queueable;

    public function __construct(public string $template, public Booking $booking, public array $vars = []) {}

    public function via(object $notifiable): array
    {
        return Messages::enabled($this->template) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $b = $this->booking->loadMissing(['station', 'charger']);
        $tz = Station::TIMEZONE;

        return Messages::mail($this->template, [
            'name' => $notifiable->name ?? ($b->customer_snapshot['name'] ?? ''),
            'booking_code' => $b->code,
            'station' => $b->station->name,
            'address' => trim("{$b->station->address_line1}, {$b->station->city}"),
            'slot' => $b->start_at->setTimezone($tz)->format('D, j M, g:i A').' – '.$b->end_at->setTimezone($tz)->format('g:i A'),
            'start_time' => $b->start_at->setTimezone($tz)->format('g:i A'),
            'charger' => (string) $b->charger?->code,
        ] + $this->vars, route('bookings.show', $b));
    }
}
