<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Station;
use App\Notifications\Concerns\TemplatedMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** A customer booked and paid: tells the station's staff (email / SMS / WhatsApp / push from Message templates). */
class NewBookingForOperator extends Notification
{
    use Queueable, TemplatedMessage;

    public function __construct(public Booking $booking) {}

    public function messageKey(): string
    {
        return 'booking.new_for_operator';
    }

    public function messageVars(object $notifiable): array
    {
        $b = $this->booking->loadMissing(['station', 'charger']);
        $tz = Station::TIMEZONE;
        $vehicle = $b->vehicle_snapshot ?? [];

        return [
            'name' => $notifiable->name ?? '',
            'booking_code' => $b->code,
            'station' => (string) $b->station?->name,
            'charger' => (string) $b->charger?->code,
            'slot' => $b->start_at->setTimezone($tz)->format('D, j M, g:i A').' – '.$b->end_at->setTimezone($tz)->format('g:i A'),
            'customer' => (string) ($b->customer_snapshot['name'] ?? $b->user?->name ?? ''),
            'vehicle' => trim(($vehicle['make'] ?? '').' '.($vehicle['model'] ?? '').' '.($vehicle['registration_no'] ?? '')),
            'amount' => number_format((float) $b->total_amount, 2),
        ];
    }

    public function messageUrl(object $notifiable): ?string
    {
        return \App\Filament\Resources\Bookings\BookingResource::getUrl('view', ['record' => $this->booking], panel: 'admin');
    }
}
