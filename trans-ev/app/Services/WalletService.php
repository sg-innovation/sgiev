<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\BusinessRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Prepaid operator wallets. In wallet billing mode:
 *  - a slot can only be reserved when the wallet's available balance (balance − fees reserved by other unpaid holds)
 *    covers this booking's platform fee (commission + per-booking fee + GST);
 *  - the fee is debited when the customer's payment succeeds, and returned pro rata when the customer is refunded;
 *  - discounts Trans EV funded through platform coupons are credited at the same time.
 * Every change is a ledger row written under a lock on the wallet row.
 */
class WalletService
{
    private static ?Company $refused = null;

    /** Email the operator whose wallet just refused a booking (call after the transaction has rolled back). */
    public function notifyRefused(): void
    {
        if (self::$refused) {
            $company = self::$refused;
            self::$refused = null;
            $this->alertLowBalance($company, force: true);
        }
    }

    public function walletFor(Company $company): Wallet
    {
        return Wallet::query()->firstOrCreate(['company_id' => $company->id], ['low_balance_alert' => BusinessRules::int('wallet.low_balance_alert')]);
    }

    public static function usesWallet(?Company $company): bool
    {
        return $company !== null && ! $company->is_platform && $company->billing_mode === 'wallet';
    }

    /** Platform fee for a booking: commission on the taxable value + per-booking fee, plus GST on both. */
    public function feeFor(Company $company, string $taxable): array
    {
        ['commission_percent' => $pct, 'per_booking_fee' => $fixed] = $company->feeRates();
        $base = bcadd(GstService::round(bcdiv(bcmul($taxable, $pct, 6), '100', 6)), $fixed, 2);
        $gst = GstService::round(bcdiv(bcmul($base, (string) BusinessRules::get('platform_fees.gst_rate'), 6), '100', 6));

        return ['base' => $base, 'gst' => $gst, 'total' => bcadd($base, $gst, 2)];
    }

    /** Fees held for unpaid bookings that are still reserving slots. */
    public function reserved(Company $company, ?int $exceptBookingId = null): string
    {
        return (string) Booking::query()->where('company_id', $company->id)->where('status', BookingStatus::Held)
            ->where('hold_expires_at', '>', now())->when($exceptBookingId, fn ($q) => $q->whereKeyNot($exceptBookingId))->sum('platform_fee');
    }

    public function available(Company $company): string
    {
        return bcsub((string) $this->walletFor($company)->balance, $this->reserved($company), 2);
    }

    /** Called inside the booking transaction: lock the wallet and refuse when the fee isn't covered. */
    public function assertCanCover(Company $company, string $fee): void
    {
        $wallet = Wallet::query()->whereKey($this->walletFor($company)->id)->lockForUpdate()->first();
        $available = bcsub((string) $wallet->balance, $this->reserved($company), 2);
        if (bccomp($available, $fee, 2) < 0) {
            self::$refused = $company; // alerted by notifyRefused() after the booking transaction rolls back
            throw ValidationException::withMessages(['start' => 'This station isn’t taking online bookings right now. Please choose another station or try again later.']);
        }
    }

    /** Debit the platform fee (and credit any Trans EV coupon) once the customer has paid. Idempotent. */
    public function chargeBooking(Booking $booking, ?Payment $payment = null): void
    {
        $company = $booking->company;
        if (! self::usesWallet($company) || bccomp((string) ($booking->platform_fee ?? 0), '0', 2) <= 0) {
            return;
        }
        if (WalletTransaction::query()->where('booking_id', $booking->id)->where('type', 'platform_fee')->exists()) {
            return;
        }
        $this->post($company, 'platform_fee', bcmul((string) $booking->platform_fee, '-1', 2), "Platform fee · booking {$booking->code}", ['booking_id' => $booking->id, 'payment_id' => $payment?->id]);
        if ($booking->discount_funded_by === 'platform' && bccomp((string) $booking->discount_amount, '0', 2) > 0) {
            $this->post($company, 'coupon_credit', (string) $booking->discount_amount, "Trans EV coupon {$booking->coupon_code} · booking {$booking->code}", ['booking_id' => $booking->id]);
        }
        $this->alertLowBalance($company);
    }

    /** Return the platform fee (and claw back the coupon credit) in proportion to a successful refund. Idempotent. */
    public function reverseForRefund(Refund $refund): void
    {
        $booking = $refund->booking()->with('company')->first();
        if (! $booking || ! self::usesWallet($booking->company) || $refund->status !== Refund::SUCCESS) {
            return;
        }
        $charged = WalletTransaction::query()->where('booking_id', $booking->id)->where('type', 'platform_fee')->first();
        if (! $charged || WalletTransaction::query()->where('refund_id', $refund->id)->exists()) {
            return;
        }
        $share = bcdiv((string) $refund->amount, (string) $booking->total_amount, 6);
        $back = GstService::round(bcmul((string) abs((float) $charged->amount), $share, 6));
        if (bccomp($back, '0', 2) > 0) {
            $this->post($booking->company, 'fee_reversal', $back, "Platform fee returned · refund on {$booking->code}", ['booking_id' => $booking->id, 'refund_id' => $refund->id]);
        }
        $coupon = WalletTransaction::query()->where('booking_id', $booking->id)->where('type', 'coupon_credit')->value('amount');
        if ($coupon) {
            $claw = GstService::round(bcmul((string) $coupon, $share, 6));
            if (bccomp($claw, '0', 2) > 0) {
                $this->post($booking->company, 'coupon_credit', bcmul($claw, '-1', 2), "Coupon reimbursement reversed · refund on {$booking->code}", ['booking_id' => $booking->id, 'refund_id' => $refund->id]);
            }
        }
    }

    /** Successful online recharge. Idempotent per payment. */
    public function creditRecharge(Payment $payment): void
    {
        if ($payment->purpose !== 'wallet' || WalletTransaction::query()->where('payment_id', $payment->id)->where('type', 'recharge')->exists()) {
            return;
        }
        $company = Company::query()->findOrFail($payment->payer_company_id);
        $this->post($company, 'recharge', (string) ($payment->paid_amount ?? $payment->amount), 'Wallet recharge (online)', [
            'payment_id' => $payment->id, 'reference' => $payment->gateway_txn_id,
        ]);
        $this->walletFor($company)->forceFill(['low_balance_alerted_at' => null])->save();
    }

    /** Manual credit or debit by Trans EV (e.g. bank transfer received, correction). */
    public function adjust(Company $company, string $amount, string $reason, string $reference, User $by): WalletTransaction
    {
        if (bccomp($amount, '0', 2) === 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount other than zero.']);
        }

        return $this->post($company, bccomp($amount, '0', 2) > 0 ? 'manual_credit' : 'manual_debit', $amount, $reason, ['reference' => $reference, 'created_by' => $by->id]);
    }

    /** Write one ledger row and move the balance (row-locked). */
    public function post(Company $company, string $type, string $amount, string $description, array $extra = []): WalletTransaction
    {
        return DB::transaction(function () use ($company, $type, $amount, $description, $extra) {
            $wallet = Wallet::query()->whereKey($this->walletFor($company)->id)->lockForUpdate()->first();
            $balance = bcadd((string) $wallet->balance, $amount, 2);
            $wallet->forceFill(['balance' => $balance])->save();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id, 'company_id' => $company->id, 'type' => $type, 'amount' => $amount, 'balance_after' => $balance,
                'description' => mb_substr($description, 0, 255), 'created_by' => $extra['created_by'] ?? auth()->id(),
            ] + array_intersect_key($extra, array_flip(['booking_id', 'payment_id', 'refund_id', 'statement_id', 'reference'])));
        });
    }

    /** Email the operator's owners when the balance is low (at most once a day unless forced by a refused booking). */
    public function alertLowBalance(Company $company, bool $force = false): void
    {
        try {
            $wallet = $this->walletFor($company);
            $low = bccomp((string) $wallet->balance, (string) $wallet->low_balance_alert, 2) < 0;
            $recent = $wallet->low_balance_alerted_at && $wallet->low_balance_alerted_at->gt(now()->subDay());
            if (! $low || ($recent && ! $force) || ($force && $wallet->low_balance_alerted_at?->gt(now()->subHour()))) {
                return;
            }
            $wallet->forceFill(['low_balance_alerted_at' => now()])->save();
            $company->users()->role(\App\Enums\Role::CompanyOwner->value)->get()
                ->each(fn (User $u) => $u->notify(new \App\Notifications\PartnerUpdate('wallet.low_balance', [
                    'company' => $company->trade_name, 'balance' => number_format((float) $wallet->balance, 2), 'blocked' => $force ? 'Customers could not book your stations just now because the balance was too low.' : '',
                ])));
        } catch (\Throwable $e) {
            Log::warning('Wallet alert failed', ['company' => $company->id, 'error' => $e->getMessage()]);
        }
    }
}
