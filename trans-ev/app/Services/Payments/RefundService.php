<?php

namespace App\Services\Payments;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Cancellation refunds per the published policy, operator-initiated refunds and automatic refunds. */
class RefundService
{
    public function __construct(private readonly GatewayFactory $gateways) {}

    public function successfulPayment(Booking $booking): ?Payment
    {
        return $booking->payments()->where('status', Payment::SUCCESS)->latest('paid_at')->first();
    }

    /**
     * What the customer gets back if they cancel now (shown before they confirm).
     *
     * @return array{paid: string, refund: string, retained: string, percent: int, rule: string}
     */
    public function quote(Booking $booking, bool $byOperator = false): array
    {
        $payment = $this->successfulPayment($booking);
        $paid = $payment ? bcsub((string) ($payment->paid_amount ?? $payment->amount), $payment->refundedAmount(), 2) : '0.00';

        if ($byOperator) {
            return ['paid' => $paid, 'refund' => $paid, 'retained' => '0.00', 'percent' => 100, 'rule' => 'Cancelled by the operator: full refund'];
        }

        $minutes = (int) floor(now()->diffInMinutes($booking->start_at, false));
        $q = \App\Models\CancellationPolicy::quote(self::policyOf($booking), $paid, $minutes);

        return ['paid' => $paid, 'refund' => $q['refund'], 'retained' => bcsub($paid, $q['refund'], 2), 'percent' => $q['percent'], 'rule' => $q['rule']];
    }

    /** The policy agreed at checkout (bookings made before policies existed use the current one). */
    public static function policyOf(Booking $booking): array
    {
        return $booking->cancellation_policy
            ?? (\App\Models\CancellationPolicy::activeFor($booking->station)?->snapshot() ?? ['tiers' => [], 'no_show_refund_percent' => 0]);
    }

    /**
     * Mark a confirmed booking as a no-show (charging not started within the no-show window) and refund
     * the no-show share of the agreed policy, if any.
     */
    public function markNoShow(Booking $booking, User $by): ?Refund
    {
        $window = \App\Support\BusinessRules::int('booking.no_show_minutes');
        if ($booking->status !== BookingStatus::Confirmed || now()->lt($booking->start_at->copy()->addMinutes($window))) {
            throw ValidationException::withMessages(['booking' => "A booking can be marked as a no-show only {$window} minutes after its start time."]);
        }
        $payment = $this->successfulPayment($booking);
        $paid = $payment ? bcsub((string) ($payment->paid_amount ?? $payment->amount), $payment->refundedAmount(), 2) : '0.00';
        $percent = (int) (self::policyOf($booking)['no_show_refund_percent'] ?? 0);
        $refund = \App\Services\GstService::round(bcdiv(bcmul($paid, (string) $percent, 4), '100', 4));

        DB::transaction(fn () => $booking->forceFill(['status' => BookingStatus::NoShow, 'no_show_at' => now()])->save());
        activity()->performedOn($booking)->causedBy($by)->withProperties(['refund_percent' => $percent])->log('marked no-show');

        if (! $payment || bccomp($refund, '0', 2) <= 0) {
            return null;
        }

        return $this->refundPayment($payment, $refund, 'No-show', "No-show ({$percent}% refund)", $by, bcsub($paid, $refund, 2));
    }

    /** Cancel a booking and refund per policy (customer) or in full (operator). */
    public function cancel(Booking $booking, string $reason, ?User $by, bool $byOperator): ?Refund
    {
        if (! in_array($booking->status, [BookingStatus::Held, BookingStatus::Confirmed], true)) {
            throw ValidationException::withMessages(['booking' => 'This booking can no longer be cancelled.']);
        }

        $quote = $this->quote($booking, $byOperator);
        $payment = $this->successfulPayment($booking);

        DB::transaction(function () use ($booking, $reason, $by) {
            $booking->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'cancelled_by' => $by?->id,
                'hold_expires_at' => null,
            ])->save();
        });

        if (! $payment || bccomp($quote['refund'], '0', 2) <= 0) {
            return null;
        }

        return $this->refundPayment($payment, $quote['refund'], $reason, $quote['rule'], $by, $quote['retained']);
    }

    /** Create the refund record and ask the gateway to refund. A failed gateway call stays visible for finance to retry. */
    public function refundPayment(Payment $payment, string $amount, string $reason, string $rule, ?User $by, string $retained = '0.00'): Refund
    {
        return DB::transaction(function () use ($payment, $amount, $reason, $rule, $by, $retained) {
            /** @var Payment $p */
            $p = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
            $available = bcsub((string) ($p->paid_amount ?? $p->amount), $p->refundedAmount(), 2);
            if ($p->status !== Payment::SUCCESS || bccomp($amount, '0', 2) <= 0 || bccomp($amount, $available, 2) > 0) {
                throw ValidationException::withMessages(['amount' => "Refund must be between ₹0.01 and ₹{$available}."]);
            }

            $refund = Refund::create([
                'company_id' => $p->company_id,
                'payment_id' => $p->id,
                'booking_id' => $p->booking_id,
                'amount' => $amount,
                'fee_retained' => $retained,
                'reason' => Str::limit($reason, 480, ''),
                'policy_rule' => Str::limit($rule, 60, ''),
                'status' => Refund::INITIATED,
                'requested_by' => $by?->id,
            ]);

            try {
                $res = $this->gateways->forPayment($p)->refund($p, $amount, $reason);
                $refund->update([
                    'gateway_refund_id' => $res['refund_id'],
                    'status' => $res['status'] === 'SUCCESS' ? Refund::SUCCESS : ($res['status'] === 'FAILED' ? Refund::FAILED : Refund::INITIATED),
                    'refunded_at' => $res['status'] === 'SUCCESS' ? now() : null,
                ]);
                $this->log($p, 'refund', 'requested', PaymentEvent::scrub($res['raw']));
            } catch (GatewayException $e) {
                $refund->update(['status' => Refund::FAILED, 'failure_reason' => Str::limit($e->getMessage(), 250)]);
                $this->log($p, 'refund', 'error', PaymentEvent::scrub($e->payload));
            }

            return $refund;
        });
    }

    /** Scheduled: update refunds still in progress. */
    public function pollPending(): int
    {
        $n = 0;
        Refund::query()->where('status', Refund::INITIATED)->whereNotNull('gateway_refund_id')->with('payment.company')->each(function (Refund $r) use (&$n) {
            try {
                $res = $this->gateways->forPayment($r->payment)->refundStatus($r->gateway_refund_id);
            } catch (GatewayException) {
                return;
            }
            if (in_array($res['status'], ['SUCCESS', 'FAILED'], true)) {
                $r->update([
                    'status' => $res['status'] === 'SUCCESS' ? Refund::SUCCESS : Refund::FAILED,
                    'bank_refund_id' => $res['bank_refund_id'],
                    'refunded_at' => $res['status'] === 'SUCCESS' ? now() : null,
                ]);
                $n++;
            }
        });

        return $n;
    }

    private function log(Payment $p, string $source, string $event, array $payload): void
    {
        PaymentEvent::create(['payment_id' => $p->id, 'company_id' => $p->company_id, 'source' => $source, 'event' => $event, 'payload' => $payload]);
    }
}
