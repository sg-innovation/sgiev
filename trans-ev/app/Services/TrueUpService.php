<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ChargingSession;
use App\Models\Coupon;
use App\Models\Payment;
use App\Notifications\BookingTrueUp;
use App\Services\Payments\RefundService;
use App\Support\BusinessRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * C4 final bill & true-up for prepaid (online) bookings. When the session stops, the bill is re-made with the energy
 * actually delivered (same tariff bands, session fee, parking for the booked slot, same coupon) and compared with what
 * the customer prepaid:
 *  - less → the difference is refunded automatically (the refund issues the GST credit note and returns the wallet fee share);
 *  - more → absorbed by the operator, or, if Business rules say so, a balance is collected at the station with a debit note;
 *  - within the tolerance → nothing to do.
 * The original invoice and payment are never changed; the final figures are stored alongside them.
 */
class TrueUpService
{
    public function __construct(private readonly PricingService $pricing) {}

    /** Final bill for a booking given the energy delivered (does not save). */
    public function finalBill(Booking $booking, string $kwh): array
    {
        $booking->loadMissing(['station', 'charger']);
        $minutes = (int) $booking->start_at->diffInMinutes($booking->end_at, true);
        $coupon = $booking->coupon_id ? Coupon::query()->find($booking->coupon_id) : null;

        return $this->pricing->estimate($booking->station, $booking->charger, (string) ($booking->vehicle_snapshot['battery_kwh'] ?? '0'),
            (int) $booking->battery_from, (int) $booking->battery_to, max(1, $minutes), $booking->start_at, $coupon, $kwh);
    }

    public function settle(Booking $booking, ChargingSession $session): Booking
    {
        if ($booking->source === 'walk_in' || $booking->trueup_status !== null || $session->energy_kwh === null) {
            return $booking;
        }
        $payment = $booking->payments()->where('status', Payment::SUCCESS)->where('purpose', 'booking')->orderBy('id')->first();
        if (! $payment) {
            return $booking;
        }

        $bill = $this->finalBill($booking, (string) $session->energy_kwh);
        $final = (string) $bill['total_amount'];
        $diff = bcsub($final, (string) $booking->total_amount, 2);
        $min = (string) BusinessRules::get('trueup.min_amount');
        $status = match (true) {
            bccomp(ltrim($diff, '-'), $min, 2) < 0 => 'none',
            bccomp($diff, '0', 2) < 0 => 'refund',
            BusinessRules::int('trueup.collect_extra') === 1 => 'due',
            default => 'absorbed',
        };

        DB::transaction(function () use ($booking, $session, $bill, $final, $diff, $status) {
            $booking->forceFill([
                'final_kwh' => $session->energy_kwh, 'final_total' => $final, 'trueup_amount' => $diff, 'final_lines' => $bill['lines'],
                'trueup_status' => $status === 'refund' ? 'refund_pending' : $status,
                'balance_due' => $status === 'due' ? $diff : null, 'settlement' => $status === 'due' ? 'due' : $booking->settlement,
            ])->save();
            activity()->performedOn($booking)->withProperties(['kwh' => (string) $session->energy_kwh, 'final' => $final, 'difference' => $diff, 'outcome' => $status])->log('final bill');
        });

        if ($status === 'refund') {
            $this->refund($booking->fresh(), $payment);
        }
        $this->notify($booking->fresh());

        return $booking->fresh();
    }

    /** Refund the shortfall (also used by "Retry refund" in admin). */
    public function refund(Booking $booking, ?Payment $payment = null): void
    {
        $payment ??= $booking->payments()->where('status', Payment::SUCCESS)->where('purpose', 'booking')->orderBy('id')->first();
        $amount = ltrim((string) $booking->trueup_amount, '-');
        try {
            $available = bcsub((string) ($payment->paid_amount ?? $payment->amount), $payment->refundedAmount(), 2);
            $amount = bccomp($amount, $available, 2) > 0 ? $available : $amount;
            app(RefundService::class)->refundPayment($payment, $amount, 'Final bill: less energy delivered ('.number_format((float) $booking->final_kwh, 2).' kWh)', 'true-up', null);
            $booking->forceFill(['trueup_status' => 'refunded'])->save();
        } catch (\Throwable $e) {
            Log::error('True-up refund failed', ['booking' => $booking->code, 'error' => $e->getMessage()]);
            $booking->forceFill(['trueup_status' => 'refund_failed'])->save();
        }
    }

    private function notify(Booking $booking): void
    {
        try {
            $booking->user?->notify(new BookingTrueUp($booking));
        } catch (\Throwable $e) {
            Log::warning('True-up email failed', ['booking' => $booking->code, 'error' => $e->getMessage()]);
        }
    }
}
