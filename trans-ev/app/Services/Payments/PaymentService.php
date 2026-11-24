<?php

namespace App\Services\Payments;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Notifications\BookingConfirmed;
use App\Services\AvailabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Payment lifecycle. Rules (security & finance audit):
 * - A booking is confirmed ONLY from a server-side status enquiry (or a signature-verified webhook), never from
 *   the browser's return URL alone.
 * - Every gateway interaction is written to the append-only payment_events log.
 * - Applying a result is idempotent and runs under row locks, so duplicate webhooks/returns cannot double-confirm.
 * - Money received for a slot that can no longer be honoured is refunded automatically.
 */
class PaymentService
{
    public function __construct(
        private readonly GatewayFactory $gateways,
        private readonly AvailabilityService $availability,
        private readonly RefundService $refunds,
    ) {}

    /** Start (or resume) payment for a held booking and return the URL to send the customer to. */
    public function start(Booking $booking): string
    {
        $booking->loadMissing('station.company');
        $company = $booking->station->company;

        if ($booking->status !== BookingStatus::Held || ! $booking->hold_expires_at?->isFuture()) {
            throw ValidationException::withMessages(['payment' => 'This booking can no longer be paid. Please book again.']);
        }
        if (! $this->gateways->canAcceptPayments($company)) {
            throw ValidationException::withMessages(['payment' => 'Online payment is not available for this station yet. Please contact the operator.']);
        }

        // Reuse a live session if the customer clicks "Pay" again.
        $open = $booking->payments()->where('status', Payment::PENDING)->where('expires_at', '>', now()->addMinutes(2))->latest('id')->first();
        if ($open) {
            return $this->gateways->forPayment($open)->redirectUrl($open);
        }

        $gateway = $this->gateways->for($company);

        return DB::transaction(function () use ($booking, $company, $gateway) {
            Booking::query()->whereKey($booking->id)->lockForUpdate()->first();

            $payment = Payment::create([
                'company_id' => $company->id,
                'booking_id' => $booking->id,
                'user_id' => $booking->user_id,
                'environment' => $gateway->environment(),
                'merchant_txn_id' => 'TEVP'.now()->format('ymdHis').Str::upper(Str::random(6)),
                'amount' => $booking->total_amount,
                'status' => Payment::CREATED,
            ]);

            try {
                $session = $gateway->createSession(
                    $payment,
                    [
                        'name' => $booking->customer_snapshot['name'] ?? 'EV Customer',
                        'email' => $booking->customer_snapshot['email'] ?? $booking->user->email,
                        'phone' => $booking->customer_snapshot['phone'] ?? '',
                    ],
                    route('payments.return', ['payment' => $payment->uuid]),
                    "EV charging {$booking->code} at {$booking->station->name}",
                );
            } catch (GatewayException $e) {
                $this->log($payment, 'create', 'failed', PaymentEvent::scrub($e->payload));
                $payment->update(['status' => Payment::FAILED, 'failure_reason' => Str::limit($e->getMessage(), 250)]);
                Log::warning('SabPaisa session failed', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);
                throw ValidationException::withMessages(['payment' => 'We could not start the payment. Please try again in a moment.']);
            }

            $payment->update([
                'status' => Payment::PENDING,
                'gateway_payment_id' => $session['payment_id'] ?: null,
                'checkout_url' => $session['checkout_url'],
                'client_secret' => $session['client_secret'],
                'expires_at' => $session['expires_at'] ? \Illuminate\Support\Carbon::parse($session['expires_at']) : now()->addMinutes(30),
            ]);
            $this->log($payment, 'create', 'session_created', PaymentEvent::scrub($session['raw']));

            // Keep the slot while the customer is on the checkout page.
            $hold = now()->addMinutes(\App\Support\BusinessRules::int('booking.checkout_hold_minutes'));
            if ($booking->hold_expires_at->lt($hold)) {
                $booking->forceFill(['hold_expires_at' => $hold])->save();
            }

            return $gateway->redirectUrl($payment);
        });
    }

    /** Online payment link for a walk-in bill (C3); the driver pays on their own phone. Returns the checkout URL. */
    public function startWalkIn(Booking $booking): string
    {
        $booking->loadMissing('station.company');
        $company = $booking->station->company;
        if (! $this->gateways->canAcceptPayments($company)) {
            throw ValidationException::withMessages(['method' => 'Online payment is not set up for this station. Take cash or UPI instead.']);
        }
        $open = $booking->payments()->where('status', Payment::PENDING)->where('expires_at', '>', now()->addMinutes(2))->latest('id')->first();
        if ($open) {
            return $this->gateways->forPayment($open)->redirectUrl($open);
        }
        $gateway = $this->gateways->for($company);

        return DB::transaction(function () use ($booking, $company, $gateway) {
            $payment = Payment::create([
                'company_id' => $company->id, 'booking_id' => $booking->id, 'user_id' => null, 'purpose' => 'booking',
                'environment' => $gateway->environment(), 'merchant_txn_id' => 'TEVW'.now()->format('ymdHis').Str::upper(Str::random(6)),
                'amount' => $booking->total_amount, 'status' => Payment::CREATED,
            ]);
            try {
                $session = $gateway->createSession($payment, [
                    'name' => $booking->customer_snapshot['name'] ?? 'EV Customer',
                    'email' => $booking->customer_snapshot['email'] ?? ($company->email ?: 'noreply@'.parse_url((string) config('app.url'), PHP_URL_HOST)),
                    'phone' => $booking->customer_snapshot['phone'] ?? '',
                ], route('payments.return', ['payment' => $payment->uuid]), "EV charging {$booking->code} at {$booking->station->name}");
            } catch (GatewayException $e) {
                $this->log($payment, 'create', 'failed', PaymentEvent::scrub($e->payload));
                $payment->update(['status' => Payment::FAILED, 'failure_reason' => Str::limit($e->getMessage(), 250)]);
                throw ValidationException::withMessages(['method' => 'We could not create the payment link. Take cash or UPI, or try again.']);
            }
            $payment->update([
                'status' => Payment::PENDING, 'gateway_payment_id' => $session['payment_id'] ?: null, 'checkout_url' => $session['checkout_url'],
                'client_secret' => $session['client_secret'], 'expires_at' => $session['expires_at'] ? \Illuminate\Support\Carbon::parse($session['expires_at']) : now()->addMinutes(30),
            ]);
            $this->log($payment, 'create', 'session_created', PaymentEvent::scrub($session['raw']));

            return $gateway->redirectUrl($payment);
        });
    }

    /** Start an online wallet recharge for an operator, paid into Trans EV's own payment account. Returns the checkout URL. */
    public function startRecharge(Company $operator, string $amount, \App\Models\User $by): string
    {
        $min = (string) \App\Support\BusinessRules::int('wallet.min_recharge');
        if (bccomp($amount, $min, 2) < 0) {
            throw ValidationException::withMessages(['amount' => "The minimum recharge is ₹{$min}."]);
        }
        $platform = Company::query()->where('is_platform', true)->firstOrFail();
        if (! $this->gateways->canAcceptPayments($platform)) {
            throw ValidationException::withMessages(['amount' => 'Online recharge is not available yet. Please pay by bank transfer and ask Trans EV to add the funds.']);
        }
        $gateway = $this->gateways->for($platform);

        return DB::transaction(function () use ($operator, $amount, $by, $platform, $gateway) {
            $payment = Payment::create([
                'company_id' => $platform->id, 'booking_id' => null, 'purpose' => 'wallet', 'payer_company_id' => $operator->id,
                'user_id' => $by->id, 'environment' => $gateway->environment(),
                'merchant_txn_id' => 'TEVW'.now()->format('ymdHis').Str::upper(Str::random(6)),
                'amount' => number_format((float) $amount, 2, '.', ''), 'status' => Payment::CREATED,
            ]);
            try {
                $session = $gateway->createSession($payment, ['name' => $operator->legal_name, 'email' => $by->email, 'phone' => (string) ($operator->phone ?? '')],
                    route('payments.return', ['payment' => $payment->uuid]), "Trans EV wallet recharge · {$operator->trade_name}");
            } catch (GatewayException $e) {
                $payment->update(['status' => Payment::FAILED, 'failure_reason' => Str::limit($e->getMessage(), 250)]);
                throw ValidationException::withMessages(['amount' => 'We could not start the payment. Please try again in a moment.']);
            }
            $payment->update([
                'status' => Payment::PENDING, 'gateway_payment_id' => $session['payment_id'] ?: null, 'checkout_url' => $session['checkout_url'],
                'client_secret' => $session['client_secret'], 'expires_at' => $session['expires_at'] ? \Illuminate\Support\Carbon::parse($session['expires_at']) : now()->addMinutes(30),
            ]);
            $this->log($payment, 'create', 'session_created', PaymentEvent::scrub($session['raw']));

            return $gateway->redirectUrl($payment);
        });
    }

    /** Customer returned from checkout. The signature is recorded, but the result is always re-checked server-side. */
    public function handleReturn(Payment $payment, array $query, ?string $ip): Payment
    {
        $gateway = $this->gateways->forPayment($payment);
        $valid = $gateway->verifyReturn($query);
        $this->log($payment, 'return', $query['status'] ?? null, PaymentEvent::scrub($query), $valid, null, $ip);

        return $this->reconcile($payment);
    }

    /** Server-to-server notification. Returns false when the signature is invalid. */
    public function handleWebhook(Company $company, string $rawBody, ?string $signature, ?string $ip): bool
    {
        if (! $company->hasPaymentSetup()) {
            return false;
        }
        $gateway = new SabPaisaGateway($company);
        $valid = $gateway->verifyWebhook($rawBody, $signature);
        $data = json_decode($rawBody, true) ?: [];
        $payment = isset($data['merchant_txn_id'])
            ? Payment::query()->where('company_id', $company->id)->where('merchant_txn_id', $data['merchant_txn_id'])->first()
            : null;
        $key = $valid ? ($data['idempotency_key'] ?? null) : null;

        // De-duplicate: the same delivery is processed once.
        if ($key && PaymentEvent::query()->where('idempotency_key', $key)->exists()) {
            return true;
        }
        $this->log($payment, 'webhook', $data['event'] ?? null, PaymentEvent::scrub($data), $valid, $key, $ip, $company->id);

        if ($valid && $payment) {
            // Webhook content is signed, but we still confirm with an enquiry before changing anything.
            $this->reconcile($payment);
        }

        return $valid;
    }

    /** Ask the gateway for the authoritative status and apply it. */
    public function reconcile(Payment $payment): Payment
    {
        if ($payment->isFinal()) {
            return $payment;
        }
        try {
            $result = $this->gateways->forPayment($payment)->enquiry($payment);
        } catch (GatewayException $e) {
            $this->log($payment, 'enquiry', 'error', PaymentEvent::scrub($e->payload));
            $payment->forceFill(['enquiry_attempts' => $payment->enquiry_attempts + 1, 'last_enquiry_at' => now()])->save();

            return $payment;
        }
        $this->log($payment, 'enquiry', $result['status'], PaymentEvent::scrub($result['raw']));

        return $this->apply($payment, $result);
    }

    /** Apply an authoritative gateway result. Idempotent. */
    private function apply(Payment $payment, array $result): Payment
    {
        $status = $result['status'];

        return DB::transaction(function () use ($payment, $result, $status) {
            /** @var Payment $p */
            $p = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
            $p->forceFill(['enquiry_attempts' => $p->enquiry_attempts + 1, 'last_enquiry_at' => now(), 'gateway_status' => $status])->save();

            if ($p->isFinal() || $status === 'PENDING') {
                // Abandoned sessions are closed once the gateway session has expired.
                if ($status === 'PENDING' && $p->expires_at?->lt(now()->subMinutes(15))) {
                    $p->forceFill(['status' => Payment::FAILED, 'failure_reason' => 'Payment not completed before the session expired', 'verified_via' => 'enquiry'])->save();
                }

                return $p;
            }

            if ($status !== 'SUCCESS') {
                $p->forceFill([
                    'status' => Payment::FAILED,
                    'gateway_txn_id' => $result['gateway_txn_id'] ?? $p->gateway_txn_id,
                    'failure_reason' => 'Gateway status: '.$status,
                    'verified_via' => $p->environment === 'simulator' ? 'simulator' : 'enquiry',
                ])->save();

                return $p;
            }

            $p->forceFill([
                'status' => Payment::SUCCESS,
                'gateway_txn_id' => $result['gateway_txn_id'],
                'paid_amount' => $result['paid_amount'] ?? $p->amount,
                'payment_mode' => $result['payment_mode'],
                'bank_txn_id' => $result['bank_txn_id'],
                'bank_rrn' => $result['bank_rrn'],
                'paid_at' => now(),
                'verified_via' => $p->environment === 'simulator' ? 'simulator' : 'enquiry',
                'client_secret' => null,
            ])->save();

            if ($p->purpose === 'wallet') {
                app(\App\Services\WalletService::class)->creditRecharge($p);
            } else {
                $this->confirmBooking($p);
            }

            return $p->fresh();
        });
    }

    /** Confirm the booking for a successful payment, or refund if the slot can no longer be honoured. */
    private function confirmBooking(Payment $payment): void
    {
        /** @var Booking $booking */
        $booking = Booking::query()->whereKey($payment->booking_id)->lockForUpdate()->first();
        if ($booking->source === 'walk_in' && app(\App\Services\WalkInService::class)->settleOnline($booking, $payment)) {
            return;
        }
        $connector = \App\Models\Connector::query()->withTrashed()->whereKey($booking->connector_id)->lockForUpdate()->first();

        $amountOk = bccomp((string) ($payment->paid_amount ?? $payment->amount), (string) $booking->total_amount, 2) === 0;
        $alreadyPaid = $booking->payments()->whereKeyNot($payment->id)->where('status', Payment::SUCCESS)->exists();
        $stillBookable = in_array($booking->status, [BookingStatus::Held, BookingStatus::Expired], true)
            && $booking->start_at->isFuture()
            && $connector !== null
            && $this->availability->isConnectorFree($connector, $booking->start_at, $booking->blocked_until, $booking->id);

        if ($amountOk && ! $alreadyPaid && $stillBookable) {
            $booking->forceFill(['status' => BookingStatus::Confirmed, 'confirmed_at' => now(), 'hold_expires_at' => null])->save();
            app(\App\Services\WalletService::class)->chargeBooking($booking->fresh('company'), $payment);
            DB::afterCommit(fn () => $booking->user?->notify(new BookingConfirmed($booking->fresh(['station', 'charger']), $payment)));
            DB::afterCommit(function () use ($booking, $payment) {
                try {
                    app(\App\Services\InvoiceService::class)->issueForBooking($booking->fresh(), $payment->fresh());
                } catch (\Throwable $e) {
                    // The booking stays confirmed; `invoices:issue-missing` (scheduled) retries.
                    Log::error('Invoice issue failed', ['booking' => $booking->code, 'error' => $e->getMessage()]);
                }
            });

            return;
        }

        $reason = ! $amountOk ? 'Paid amount did not match the booking amount'
            : ($alreadyPaid ? 'Duplicate payment for an already paid booking' : 'Payment received after the slot was released');
        $payment->forceFill(['failure_reason' => $reason])->save();
        $paid = (string) ($payment->paid_amount ?? $payment->amount);
        if (bccomp($paid, '0', 2) <= 0) {
            return; // nothing was actually collected
        }
        DB::afterCommit(function () use ($payment, $paid, $reason) {
            try {
                $this->refunds->refundPayment($payment->fresh(), $paid, $reason, 'automatic', null);
            } catch (\Throwable $e) {
                // Never break webhook/return handling; the payment is flagged for finance to refund manually.
                Log::error('Automatic refund failed', ['payment' => $payment->uuid, 'error' => $e->getMessage()]);
            }
        });
    }

    public function log(?Payment $payment, string $source, ?string $event, array $payload = [], ?bool $valid = null, ?string $key = null, ?string $ip = null, ?int $companyId = null): void
    {
        PaymentEvent::create([
            'payment_id' => $payment?->id,
            'company_id' => $companyId ?? $payment?->company_id,
            'source' => $source,
            'event' => $event ? Str::limit($event, 60, '') : null,
            'idempotency_key' => $key,
            'signature_valid' => $valid,
            'payload' => $payload,
            'ip' => $ip,
        ]);
    }
}
