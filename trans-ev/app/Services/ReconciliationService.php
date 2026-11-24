<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\ReconciliationException;
use App\Models\ReconciliationRun;
use App\Models\Refund;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Payments\GatewayException;
use App\Services\Payments\GatewayFactory;
use App\Services\Payments\PaymentService;
use App\Support\BusinessRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * B8 reconciliation. Once a day (and on demand) every money movement is cross-checked:
 *  - the payment gateway (SabPaisa enquiry) against our payment records, for the last few days;
 *  - payments against bookings (paid but not booked, booked but not paid, amount differences, duplicates);
 *  - refunds (stuck, failed, more than was paid);
 *  - GST documents (invoice / credit note missing after the grace period);
 *  - operator wallets (platform fee not taken, recharge not credited).
 * Each problem becomes one exception (deduplicated by fingerprint) in the queue at Billing & GST → Reconciliation.
 * Exceptions whose problem has gone away are cleared automatically on the next run.
 */
class ReconciliationService
{
    /** Check types: label, severity, and whether a safe one-click fix exists. */
    public const TYPES = [
        'gateway_status' => ['label' => 'Gateway status differs from ours', 'severity' => 'critical', 'fix' => false],
        'gateway_amount' => ['label' => 'Gateway amount differs from ours', 'severity' => 'critical', 'fix' => false],
        'payment_pending' => ['label' => 'Payment still pending', 'severity' => 'high', 'fix' => true],
        'paid_not_booked' => ['label' => 'Paid, but no booking and no refund', 'severity' => 'critical', 'fix' => false],
        'booked_unpaid' => ['label' => 'Booking confirmed without a successful payment', 'severity' => 'high', 'fix' => false],
        'amount_mismatch' => ['label' => 'Paid amount differs from booking total', 'severity' => 'high', 'fix' => false],
        'over_refunded' => ['label' => 'Refunded more than was paid', 'severity' => 'critical', 'fix' => false],
        'refund_stuck' => ['label' => 'Refund not confirmed by the bank', 'severity' => 'high', 'fix' => true],
        'refund_failed' => ['label' => 'Refund failed and not retried', 'severity' => 'critical', 'fix' => false],
        'invoice_missing' => ['label' => 'GST invoice missing', 'severity' => 'medium', 'fix' => true],
        'credit_note_missing' => ['label' => 'Credit note missing for a refund', 'severity' => 'medium', 'fix' => true],
        'wallet_fee_missing' => ['label' => 'Platform fee not taken from wallet', 'severity' => 'medium', 'fix' => true],
        'wallet_recharge_missing' => ['label' => 'Wallet recharge paid but not credited', 'severity' => 'critical', 'fix' => true],
    ];

    /** Booking statuses that mean "the customer paid for this slot". */
    private const PAID_STATUSES = ['confirmed', 'checked_in', 'completed', 'no_show', 'cancelled'];

    /** @var array<string, array> findings of the current pass keyed by fingerprint */
    private array $found = [];

    /** @var array<int, true> payments the gateway was asked about in this pass */
    private array $enquired = [];

    private array $stats = [];

    public function __construct(private readonly GatewayFactory $gateways, private readonly PaymentService $payments) {}

    public static function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? $type;
    }

    /** Full pass. $enquire = false skips the gateway calls (database checks only). */
    public function run(?User $by = null, bool $enquire = true): ReconciliationRun
    {
        $run = ReconciliationRun::create(['trigger' => $by ? 'manual' : 'schedule', 'triggered_by' => $by?->id, 'started_at' => now()]);
        $this->found = $this->enquired = [];
        $this->stats = ['payments_checked' => 0, 'enquiries' => 0, 'enquiry_errors' => 0];

        try {
            if ($enquire) {
                $this->checkGateway();
            }
            $this->checkPending($enquire);
            $this->checkPaidNotBooked();
            $this->checkBookedUnpaid();
            $this->checkAmounts();
            $this->checkRefunds();
            $this->checkDocuments();
            $this->checkWallets();
            [$new, $cleared] = $this->record($run, $enquire);
            $this->stats += $this->dayTotals();
            $run->update([
                'finished_at' => now(), 'stats' => $this->stats, 'new_exceptions' => $new, 'cleared_exceptions' => $cleared,
                'open_exceptions' => ReconciliationException::where('status', ReconciliationException::OPEN)->count(),
            ]);
            $this->alert($run);
        } catch (\Throwable $e) {
            Log::error('Reconciliation failed', ['run' => $run->id, 'error' => $e->getMessage()]);
            $run->update(['finished_at' => now(), 'stats' => $this->stats, 'error' => mb_substr($e->getMessage(), 0, 2000)]);
        }

        return $run->fresh();
    }

    /** Re-run the check behind one exception now (after a fix or a manual correction) and clear it if it has gone. */
    public function recheck(ReconciliationException $ex): bool
    {
        $this->found = $this->enquired = [];
        $this->stats = ['payments_checked' => 0, 'enquiries' => 0, 'enquiry_errors' => 0];
        match ($ex->type) {
            'gateway_status', 'gateway_amount' => $ex->payment ? $this->checkGateway($ex->payment_id) : null,
            'payment_pending' => $this->checkPending(true, $ex->payment_id),
            'paid_not_booked' => $this->checkPaidNotBooked($ex->payment_id),
            'booked_unpaid' => $this->checkBookedUnpaid($ex->booking_id),
            'amount_mismatch' => $this->checkAmounts($ex->booking_id),
            'over_refunded', 'refund_stuck', 'refund_failed' => $this->checkRefunds($ex->payment_id),
            'invoice_missing', 'credit_note_missing' => $this->checkDocuments($ex->booking_id),
            'wallet_fee_missing', 'wallet_recharge_missing' => $this->checkWallets($ex->booking_id, $ex->payment_id),
            default => null,
        };
        if (isset($this->found[$ex->fingerprint])) {
            $ex->update(['last_seen_at' => now()]);

            return false;
        }
        if ($ex->status === ReconciliationException::OPEN) {
            $ex->update(['status' => ReconciliationException::RESOLVED, 'resolved_at' => now(), 'resolved_by' => auth()->id(), 'resolution_note' => 'Cleared on re-check']);
        }

        return true;
    }

    public static function canFix(ReconciliationException $ex): bool
    {
        return (self::TYPES[$ex->type]['fix'] ?? false) && $ex->status === ReconciliationException::OPEN;
    }

    /** Apply the safe automatic fix for an exception, then re-check it. Returns true when the problem is gone. */
    public function fix(ReconciliationException $ex): bool
    {
        $booking = $ex->booking;
        $payment = $ex->payment;
        switch ($ex->type) {
            case 'payment_pending':
                $payment && $this->payments->reconcile($payment);
                break;
            case 'refund_stuck':
                app(Payments\RefundService::class)->pollPending();
                break;
            case 'invoice_missing':
                $booking && app(InvoiceService::class)->issueForBooking($booking);
                break;
            case 'credit_note_missing':
                $ex->refund && app(InvoiceService::class)->creditNoteForRefund($ex->refund);
                break;
            case 'wallet_fee_missing':
                $booking && app(WalletService::class)->chargeBooking($booking->load('company'), $payment);
                break;
            case 'wallet_recharge_missing':
                $payment && app(WalletService::class)->creditRecharge($payment);
                break;
        }

        return $this->recheck($ex->fresh());
    }

    // ── Checks ──────────────────────────────────────────────────────────────────────────────────────────────

    /** Ask the gateway about settled payments of the last few days and compare status and amount. */
    private function checkGateway(?int $paymentId = null): void
    {
        Payment::query()->whereIn('status', [Payment::SUCCESS, Payment::FAILED])->whereNotNull('merchant_txn_id')
            ->when($paymentId, fn ($q) => $q->whereKey($paymentId), fn ($q) => $q->where('created_at', '>=', now()->subDays(BusinessRules::int('reconciliation.window_days'))))
            ->orderBy('id')->limit(BusinessRules::int('reconciliation.max_enquiries'))
            ->get()->each(function (Payment $p) {
                $this->stats['enquiries']++;
                try {
                    $g = $this->gateways->forPayment($p)->enquiry($p);
                } catch (GatewayException|\Throwable $e) {
                    $this->stats['enquiry_errors']++;

                    return;
                }
                $this->enquired[$p->id] = true;
                $theirs = $g['status'];
                if ($theirs === 'PENDING') {
                    return; // inconclusive
                }
                $ours = $p->status === Payment::SUCCESS ? 'SUCCESS' : 'FAILED';
                $theirsNorm = $theirs === 'SUCCESS' ? 'SUCCESS' : 'FAILED';
                if ($ours !== $theirsNorm) {
                    $this->flag('gateway_status', $p, null, null, $ours === 'FAILED'
                        ? "Gateway says {$p->merchant_txn_id} was paid, but we recorded it as failed: the customer may have been charged."
                        : "Gateway says {$p->merchant_txn_id} is {$theirs}, but we recorded it as paid.",
                        $p->paid_amount ?? $p->amount, $g['paid_amount'], ['ours' => $ours, 'gateway' => $theirs, 'gateway_txn_id' => $g['gateway_txn_id']]);

                    return;
                }
                if ($ours === 'SUCCESS' && $g['paid_amount'] !== null && bccomp((string) $g['paid_amount'], (string) ($p->paid_amount ?? $p->amount), 2) !== 0) {
                    $this->flag('gateway_amount', $p, null, null, "Gateway amount for {$p->merchant_txn_id} is ₹{$g['paid_amount']}; we recorded ₹".($p->paid_amount ?? $p->amount).'.',
                        $p->paid_amount ?? $p->amount, $g['paid_amount']);
                }
            });
    }

    /** Payments sent to checkout that are still pending well after they should have settled. */
    private function checkPending(bool $enquire, ?int $paymentId = null): void
    {
        Payment::query()->where('status', Payment::PENDING)
            ->when($paymentId, fn ($q) => $q->whereKey($paymentId))
            ->where('created_at', '<', now()->subMinutes(BusinessRules::int('reconciliation.pending_minutes')))
            ->get()->each(function (Payment $p) use ($enquire) {
                if ($enquire) {
                    $p = $this->payments->reconcile($p)->fresh();
                    $this->enquired[$p->id] = true;
                }
                if ($p->status === Payment::PENDING) {
                    $this->flag('payment_pending', $p, null, null, "Payment {$p->merchant_txn_id} has been pending since ".$p->created_at->timezone('Asia/Kolkata')->format('d M, h:i A').'.', $p->amount, null);
                }
            });
    }

    /** Money received for a booking that was never confirmed (or a duplicate payment) and not refunded. */
    private function checkPaidNotBooked(?int $paymentId = null): void
    {
        $grace = now()->subMinutes(BusinessRules::int('reconciliation.pending_minutes'));
        Payment::query()->where('status', Payment::SUCCESS)->where('purpose', 'booking')->whereNotNull('booking_id')
            ->when($paymentId, fn ($q) => $q->whereKey($paymentId))
            ->where(fn ($q) => $q->where('paid_at', '<', $grace)->orWhereNull('paid_at'))
            ->with('booking')->withSum(['refunds as refunded' => fn ($q) => $q->whereIn('status', [Refund::INITIATED, Refund::SUCCESS])], 'amount')
            ->get()->each(function (Payment $p) {
                $b = $p->booking;
                $paid = (string) ($p->paid_amount ?? $p->amount);
                $refunded = (string) ($p->refunded ?? '0');
                if (! $b || bccomp($refunded, $paid, 2) >= 0) {
                    return;
                }
                $duplicate = Payment::query()->where('booking_id', $b->id)->where('status', Payment::SUCCESS)->where('id', '<', $p->id)->exists();
                $unbooked = ! in_array($b->status->value, self::PAID_STATUSES, true);
                if ($duplicate || $unbooked) {
                    $this->flag('paid_not_booked', $p, $b, null, $duplicate
                        ? "Second successful payment for booking {$b->code}; only one should be kept."
                        : "Payment received for booking {$b->code} but the booking is ".$b->status->getLabel().' and nothing was refunded.',
                        '0.00', bcsub($paid, $refunded, 2), ['booking_status' => $b->status->value, 'duplicate' => $duplicate]);
                }
            });
    }

    private function checkBookedUnpaid(?int $bookingId = null): void
    {
        Booking::query()->whereIn('status', ['confirmed', 'checked_in', 'completed', 'no_show'])->where('total_amount', '>', 0)
            ->when($bookingId, fn ($q) => $q->whereKey($bookingId))
            ->whereDoesntHave('payments', fn ($q) => $q->where('status', Payment::SUCCESS))
            ->get()->each(fn (Booking $b) => $this->flag('booked_unpaid', null, $b, null,
                "Booking {$b->code} is ".$b->status->getLabel().' but has no successful payment.', $b->total_amount, '0.00'));
    }

    private function checkAmounts(?int $bookingId = null): void
    {
        Booking::query()->whereIn('status', self::PAID_STATUSES)->when($bookingId, fn ($q) => $q->whereKey($bookingId))
            ->with(['payments' => fn ($q) => $q->where('status', Payment::SUCCESS)->orderBy('id')])
            ->whereHas('payments', fn ($q) => $q->where('status', Payment::SUCCESS))
            ->get()->each(function (Booking $b) {
                $p = $b->payments->first();
                $paid = (string) ($p->paid_amount ?? $p->amount);
                if (bccomp($paid, (string) $b->total_amount, 2) !== 0) {
                    $this->flag('amount_mismatch', $p, $b, null, "Booking {$b->code} totals ₹{$b->total_amount} but ₹{$paid} was collected.", $b->total_amount, $paid);
                }
            });
    }

    private function checkRefunds(?int $paymentId = null): void
    {
        $stuck = now()->subHours(BusinessRules::int('reconciliation.refund_stuck_hours'));
        Refund::query()->whereIn('status', [Refund::INITIATED, Refund::FAILED])->when($paymentId, fn ($q) => $q->where('payment_id', $paymentId))
            ->with(['payment', 'booking'])->get()->each(function (Refund $r) use ($stuck) {
                if ($r->status === Refund::INITIATED && $r->created_at->lt($stuck)) {
                    $this->flag('refund_stuck', $r->payment, $r->booking, $r, "Refund of ₹{$r->amount} on booking {$r->booking?->code} started ".$r->created_at->timezone('Asia/Kolkata')->format('d M, h:i A').' and is not confirmed yet.', $r->amount, null);
                }
                if ($r->status === Refund::FAILED) {
                    $retried = Refund::query()->where('payment_id', $r->payment_id)->where('id', '>', $r->id)->whereIn('status', [Refund::INITIATED, Refund::SUCCESS])->exists();
                    if (! $retried) {
                        $this->flag('refund_failed', $r->payment, $r->booking, $r, "Refund of ₹{$r->amount} on booking {$r->booking?->code} failed".($r->failure_reason ? ": {$r->failure_reason}" : '').' and has not been retried.', $r->amount, '0.00');
                    }
                }
            });

        Payment::query()->where('status', Payment::SUCCESS)->when($paymentId, fn ($q) => $q->whereKey($paymentId))
            ->withSum(['refunds as refunded' => fn ($q) => $q->whereIn('status', [Refund::INITIATED, Refund::SUCCESS])], 'amount')
            ->whereHas('refunds')->with('booking')->get()->each(function (Payment $p) {
                $paid = (string) ($p->paid_amount ?? $p->amount);
                if (bccomp((string) $p->refunded, $paid, 2) > 0) {
                    $this->flag('over_refunded', $p, $p->booking, null, "₹{$p->refunded} refunded against a payment of ₹{$paid}.", $paid, $p->refunded);
                }
            });
    }

    private function checkDocuments(?int $bookingId = null): void
    {
        $grace = now()->subHours(BusinessRules::int('reconciliation.document_grace_hours'));
        Booking::query()->whereIn('status', self::PAID_STATUSES)->when($bookingId, fn ($q) => $q->whereKey($bookingId))
            ->whereDoesntHave('invoice')
            ->whereHas('payments', fn ($q) => $q->where('status', Payment::SUCCESS)->where('paid_at', '<', $grace))
            ->with('station.company')->get()->each(function (Booking $b) {
                $noGstin = $b->station && ! app(GstService::class)->invoiceGstin($b->station);
                $this->flag('invoice_missing', null, $b, null, "No GST invoice for paid booking {$b->code}.".($noGstin ? ' The operator has no GSTIN for the station’s state; add it to the company profile.' : ''),
                    $b->total_amount, null, ['no_gstin' => $noGstin]);
            });

        Refund::query()->where('status', Refund::SUCCESS)->where('amount', '>', 0)->where('refunded_at', '<', $grace)
            ->when($bookingId, fn ($q) => $q->where('booking_id', $bookingId))
            ->whereNotIn('id', CreditNote::query()->whereNotNull('refund_id')->select('refund_id'))
            ->whereHas('booking', fn ($q) => $q->whereHas('invoice'))
            ->with('booking')->get()->each(fn (Refund $r) => $this->flag('credit_note_missing', null, $r->booking, $r, "No credit note for the ₹{$r->amount} refund on booking {$r->booking->code}.", $r->amount, null));
    }

    private function checkWallets(?int $bookingId = null, ?int $paymentId = null): void
    {
        $walletCompanies = Company::query()->where('is_platform', false)->where('billing_mode', 'wallet')->pluck('id');
        if ($paymentId === null || $bookingId !== null) {
            Booking::query()->whereIn('company_id', $walletCompanies)->whereIn('status', self::PAID_STATUSES)->where('platform_fee', '>', 0)
                ->when($bookingId, fn ($q) => $q->whereKey($bookingId))
                ->whereHas('payments', fn ($q) => $q->where('status', Payment::SUCCESS))
                ->whereNotIn('id', WalletTransaction::query()->where('type', 'platform_fee')->whereNotNull('booking_id')->select('booking_id'))
                ->get()->each(fn (Booking $b) => $this->flag('wallet_fee_missing', null, $b, null, "Platform fee of ₹{$b->platform_fee} for booking {$b->code} was not taken from the wallet.", $b->platform_fee, '0.00'));
        }
        if ($bookingId === null) {
            Payment::query()->where('purpose', 'wallet')->where('status', Payment::SUCCESS)->when($paymentId, fn ($q) => $q->whereKey($paymentId))
                ->whereNotIn('id', WalletTransaction::query()->where('type', 'recharge')->whereNotNull('payment_id')->select('payment_id'))
                ->get()->each(fn (Payment $p) => $this->flag('wallet_recharge_missing', $p, null, null, "Wallet recharge {$p->merchant_txn_id} of ₹".($p->paid_amount ?? $p->amount).' was paid but not credited.', $p->paid_amount ?? $p->amount, '0.00', ['company_id' => $p->payer_company_id]));
        }
    }

    // ── Recording ───────────────────────────────────────────────────────────────────────────────────────────

    private function flag(string $type, ?Payment $p, ?Booking $b, ?Refund $r, string $summary, $expected, $actual, array $details = []): void
    {
        $fp = implode(':', [$type, $p?->id ?? '-', $b?->id ?? $p?->booking_id ?? '-', $r?->id ?? '-']);
        $this->found[$fp] = [
            'fingerprint' => $fp, 'type' => $type, 'severity' => self::TYPES[$type]['severity'],
            'company_id' => $details['company_id'] ?? $b?->company_id ?? $r?->company_id ?? ($p?->purpose === 'wallet' ? $p->payer_company_id : $p?->company_id),
            'booking_id' => $b?->id ?? $p?->booking_id, 'payment_id' => $p?->id, 'refund_id' => $r?->id,
            'summary' => mb_substr($summary, 0, 255), 'expected_amount' => $expected, 'actual_amount' => $actual,
            'details' => array_diff_key($details, ['company_id' => 1]) ?: null,
        ];
    }

    /** Save findings and clear open exceptions that no longer occur. @return array{int, int} [new, cleared] */
    private function record(ReconciliationRun $run, bool $enquired): array
    {
        $new = 0;
        foreach ($this->found as $fp => $row) {
            $ex = ReconciliationException::query()->where('fingerprint', $fp)->first();
            if (! $ex) {
                ReconciliationException::create($row + ['first_seen_at' => now(), 'last_seen_at' => now(), 'first_run_id' => $run->id, 'last_run_id' => $run->id]);
                $new++;

                continue;
            }
            $update = ['summary' => $row['summary'], 'actual_amount' => $row['actual_amount'], 'expected_amount' => $row['expected_amount'],
                'last_seen_at' => now(), 'last_run_id' => $run->id, 'occurrences' => $ex->occurrences + 1];
            if ($ex->status === ReconciliationException::RESOLVED) {
                // Marked resolved by hand but still happening: reopen.
                $update += ['status' => ReconciliationException::OPEN, 'resolved_at' => null, 'resolved_by' => null, 'resolution_note' => trim(($ex->resolution_note ?? '')."\nReopened: still found on ".now('Asia/Kolkata')->format('d M Y'))];
                $new++;
            }
            $ex->update($update);
        }

        $cleared = 0;
        ReconciliationException::query()->where('status', ReconciliationException::OPEN)->whereNotIn('fingerprint', array_keys($this->found) ?: ['-'])
            ->get()->each(function (ReconciliationException $ex) use (&$cleared, $enquired) {
                // Gateway findings are only cleared when that payment was asked about again in this pass.
                if (in_array($ex->type, ['gateway_status', 'gateway_amount'], true) && ! ($enquired && isset($this->enquired[$ex->payment_id]))) {
                    return;
                }
                $ex->update(['status' => ReconciliationException::RESOLVED, 'resolved_at' => now(), 'resolution_note' => 'Cleared automatically: no longer found']);
                $cleared++;
            });

        return [$new, $cleared];
    }

    /** Yesterday's (IST) collections and refunds, for the run summary. */
    private function dayTotals(): array
    {
        $from = now('Asia/Kolkata')->subDay()->startOfDay()->utc();
        $to = now('Asia/Kolkata')->startOfDay()->utc();
        $paid = Payment::query()->where('status', Payment::SUCCESS)->whereBetween('paid_at', [$from, $to]);

        return [
            'day' => $from->copy()->timezone('Asia/Kolkata')->toDateString(),
            'collected_count' => (clone $paid)->count(),
            'collected' => number_format((float) (clone $paid)->sum('paid_amount'), 2, '.', ''),
            'refunded' => number_format((float) Refund::query()->where('status', Refund::SUCCESS)->whereBetween('refunded_at', [$from, $to])->sum('amount'), 2, '.', ''),
        ];
    }

    /** Email Trans EV staff who handle reconciliation when a run finds new critical or high exceptions. */
    private function alert(ReconciliationRun $run): void
    {
        $urgent = ReconciliationException::query()->where('last_run_id', $run->id)->where('status', ReconciliationException::OPEN)
            ->whereIn('severity', ['critical', 'high'])->where('first_run_id', $run->id)->get();
        if ($urgent->isEmpty()) {
            return;
        }
        try {
            $this->staff()->each(fn (User $u) => $u->notify(new \App\Notifications\PartnerUpdate('reconciliation.alert', [
                'count' => (string) $urgent->count(),
                'summary' => $urgent->take(10)->map(fn ($e) => '- '.self::label($e->type).': '.$e->summary)->implode("\n"),
            ])));
        } catch (\Throwable $e) {
            Log::warning('Reconciliation alert failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return Collection<int, User> */
    private function staff(): Collection
    {
        $platform = Company::query()->where('is_platform', true)->value('id');

        return User::query()->where('company_id', $platform)->get()->filter(fn (User $u) => $u->can('reconciliation.manage'));
    }
}
