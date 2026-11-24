<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Station;
use App\Notifications\Concerns\TemplatedMessage;
use App\Support\BusinessRules;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Booking confirmed after payment (email / SMS / WhatsApp / push from Message templates). */
class BookingConfirmed extends Notification
{
    use Queueable, TemplatedMessage;

    public function __construct(public Booking $booking, public Payment $payment) {}

    public function messageKey(): string
    {
        return 'booking.confirmed';
    }

    public function messageVars(object $notifiable): array
    {
        $b = $this->booking;
        $tz = Station::TIMEZONE;

        return [
            'name' => $notifiable->name ?? '',
            'booking_code' => $b->code,
            'station' => $b->station->name,
            'slot' => $b->start_at->setTimezone($tz)->format('D, j M Y, g:i A').' – '.$b->end_at->setTimezone($tz)->format('g:i A'),
            'charger' => "{$b->charger->code} · {$b->charger->type->value} {$b->charger->power_kw} kW",
            'amount' => number_format((float) ($this->payment->paid_amount ?? $this->payment->amount), 2),
            'payment_ref' => (string) $this->payment->gateway_txn_id,
            'no_show_minutes' => (string) BusinessRules::int('booking.no_show_minutes'),
        ];
    }

    public function messageUrl(object $notifiable): ?string
    {
        return route('bookings.show', $this->booking);
    }
}
