<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Station;
use App\Support\BusinessRules;
use App\Support\Messages;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Wording: Admin → Content → Message templates → “Booking confirmed”. */
class BookingConfirmed extends Notification
{
    use Queueable;

    public function __construct(public Booking $booking, public Payment $payment) {}

    public function via(object $notifiable): array
    {
        return Messages::enabled('booking.confirmed') ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $b = $this->booking;
        $tz = Station::TIMEZONE;

        return Messages::mail('booking.confirmed', [
            'name' => $notifiable->name ?? '',
            'booking_code' => $b->code,
            'station' => $b->station->name,
            'slot' => $b->start_at->setTimezone($tz)->format('D, j M Y, g:i A').' – '.$b->end_at->setTimezone($tz)->format('g:i A'),
            'charger' => "{$b->charger->code} · {$b->charger->type->value} {$b->charger->power_kw} kW",
            'amount' => number_format((float) ($this->payment->paid_amount ?? $this->payment->amount), 2),
            'payment_ref' => (string) $this->payment->gateway_txn_id,
            'no_show_minutes' => (string) BusinessRules::int('booking.no_show_minutes'),
        ], route('bookings.show', $b));
    }
}
