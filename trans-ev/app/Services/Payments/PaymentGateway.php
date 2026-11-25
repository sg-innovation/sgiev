<?php

namespace App\Services\Payments;

use App\Models\Payment;

/** A payment gateway account of one operating company. */
interface PaymentGateway
{
    /** Short gateway name stored on payments (sabpaisa, razorpay). */
    public function name(): string;

    public function environment(): string;

    /**
     * Create a hosted-checkout session.
     *
     * @return array{payment_id: string, checkout_url: string, client_secret: ?string, expires_at: ?string, raw: array}
     */
    public function createSession(Payment $payment, array $customer, string $returnUrl, string $description): array;

    /** URL the customer's browser is sent to. */
    public function redirectUrl(Payment $payment): string;

    /** Verify the signed query parameters on the return URL (a hint only; status is always re-checked by enquiry). */
    public function verifyReturn(array $query): bool;

    /**
     * Authoritative status from the gateway.
     *
     * @return array{status: string, gateway_txn_id: ?string, paid_amount: ?string, payment_mode: ?string, bank_txn_id: ?string, bank_rrn: ?string, raw: array}
     *                                                                                                                                                       status is one of SUCCESS, FAILED, EXPIRED, TIMEOUT, CANCELLED, PENDING
     */
    public function enquiry(Payment $payment): array;

    /** Verify a webhook signature over the raw request body. */
    public function verifyWebhook(string $rawBody, ?string $signatureHeader): bool;

    /**
     * Start a refund.
     *
     * @return array{refund_id: ?string, status: string, raw: array}
     */
    public function refund(Payment $payment, string $amount, string $reason): array;

    /**
     * @return array{status: string, bank_refund_id: ?string, raw: array}
     */
    public function refundStatus(string $refundId): array;
}
