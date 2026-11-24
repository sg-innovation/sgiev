<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Support\Messages;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Charging summary after the session: energy delivered, final bill, refund or balance (wording from Message templates). */
class BookingTrueUp extends Notification
{
    use Queueable;

    public function __construct(public Booking $booking) {}

    public function via(object $notifiable): array
    {
        return Messages::enabled('booking.trueup') ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $b = $this->booking;
        $diff = (float) $b->trueup_amount;
        $outcome = match ($b->trueup_status) {
            'refunded' => '₹'.number_format(abs($diff), 2).' is being refunded to your original payment method. A GST credit note is attached to a separate email.',
            'refund_failed' => 'A refund of ₹'.number_format(abs($diff), 2).' is due to you. Our team is processing it.',
            'absorbed' => 'More energy was delivered than you prepaid for. There is nothing more to pay.',
            'due' => 'A balance of ₹'.number_format((float) $b->balance_due, 2).' is due for the extra energy. Please pay at the station.',
            default => 'Your prepaid amount covers the energy delivered. There is nothing more to pay.',
        };

        return Messages::mail('booking.trueup', [
            'name' => $notifiable->name ?? ($b->customer_snapshot['name'] ?? ''), 'booking_code' => $b->code, 'station' => $b->station?->name ?? '',
            'kwh' => number_format((float) $b->final_kwh, 2), 'prepaid' => number_format((float) $b->total_amount, 2),
            'final' => number_format((float) $b->final_total, 2), 'outcome' => $outcome,
        ], route('bookings.show', $b));
    }
}
