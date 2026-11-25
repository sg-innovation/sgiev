<?php

namespace App\Services\Payments;

use App\Models\Company;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Razorpay (https://razorpay.com/docs/api) as an alternative to SabPaisa, per company.
 * Uses hosted **Payment Links**: we create a link (reference_id = our merchant_txn_id), send the customer to its
 * short URL, and Razorpay returns them to our return URL with a signed query. Status always comes from the API
 * (payment link → payment), never from the browser. Refunds use the Payments Refund API. Webhooks are verified
 * with HMAC-SHA256 of the raw body using the webhook secret (header X-Razorpay-Signature).
 * Test vs live is decided by the key: rzp_test_… or rzp_live_….
 */
class RazorpayGateway implements PaymentGateway
{
    public const BASE_URL = 'https://api.razorpay.com/v1';

    public function __construct(private readonly Company $company) {}

    public function name(): string
    {
        return 'razorpay';
    }

    public function environment(): string
    {
        return str_starts_with((string) $this->company->razorpay_key_id, 'rzp_live_') ? 'production' : 'test';
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)->acceptJson()->asJson()->timeout(20)
            ->withBasicAuth((string) $this->company->razorpay_key_id, (string) $this->company->razorpay_key_secret);
    }

    public function createSession(Payment $payment, array $customer, string $returnUrl, string $description): array
    {
        $expires = now()->addMinutes(30);
        $res = $this->http()->post('/payment_links', array_filter([
            'amount' => SabPaisaGateway::paise((string) $payment->amount),
            'currency' => 'INR',
            'accept_partial' => false,
            'reference_id' => $payment->merchant_txn_id,
            'description' => Str::limit($description, 2000, ''),
            'customer' => array_filter(['name' => Str::limit((string) ($customer['name'] ?? ''), 100, ''), 'email' => $customer['email'] ?? null,
                'contact' => filled($customer['phone'] ?? null) ? '+91'.substr(preg_replace('/\D/', '', $customer['phone']), -10) : null]),
            'notify' => ['sms' => false, 'email' => false],
            'reminder_enable' => false,
            'callback_url' => $returnUrl,
            'callback_method' => 'get',
            'expire_by' => $expires->getTimestamp(),
            'notes' => ['payment_uuid' => $payment->uuid, 'company' => $this->company->slug],
        ], fn ($v) => $v !== null && $v !== []));
        $json = (array) $res->json();
        if (! $res->successful() || empty($json['short_url'])) {
            throw new GatewayException(self::errorMessage($json, $res->status()), $json);
        }

        return ['payment_id' => (string) $json['id'], 'checkout_url' => (string) $json['short_url'], 'client_secret' => null,
            'expires_at' => $expires->toIso8601String(), 'raw' => $json];
    }

    public function redirectUrl(Payment $payment): string
    {
        return (string) $payment->checkout_url;
    }

    /** Signature = HMAC-SHA256(link_id|reference_id|link_status|payment_id, key_secret). */
    public function verifyReturn(array $query): bool
    {
        $fields = ['razorpay_payment_link_id', 'razorpay_payment_link_reference_id', 'razorpay_payment_link_status', 'razorpay_payment_id'];
        $sig = $query['razorpay_signature'] ?? null;
        if (! is_string($sig) || array_diff($fields, array_keys($query))) {
            return false;
        }
        $data = implode('|', array_map(fn ($f) => (string) $query[$f], $fields));

        return hash_equals(hash_hmac('sha256', $data, (string) $this->company->razorpay_key_secret), $sig);
    }

    public function enquiry(Payment $payment): array
    {
        $pending = ['status' => 'PENDING', 'gateway_txn_id' => null, 'paid_amount' => null, 'payment_mode' => null, 'bank_txn_id' => null, 'bank_rrn' => null];
        if (! $payment->gateway_payment_id) {
            return $pending + ['raw' => []];
        }
        $res = $this->http()->get('/payment_links/'.rawurlencode($payment->gateway_payment_id));
        $json = (array) $res->json();
        if (! $res->successful()) {
            throw new GatewayException(self::errorMessage($json, $res->status()), $json);
        }
        $status = strtolower((string) ($json['status'] ?? 'created'));
        if ($status !== 'paid') {
            return ['status' => match ($status) { 'expired' => 'EXPIRED', 'cancelled' => 'CANCELLED', default => 'PENDING' }] + array_slice($pending, 1, null, true) + ['raw' => $json];
        }
        $paid = collect($json['payments'] ?? [])->first(fn ($p) => in_array($p['status'] ?? '', ['captured', 'authorized'], true)) ?? collect($json['payments'] ?? [])->first();
        $paymentId = $paid['payment_id'] ?? null;
        $detail = $paymentId ? (array) $this->http()->get('/payments/'.rawurlencode($paymentId), ['expand[]' => 'card'])->json() : [];
        $instrument = match ($detail['method'] ?? null) {
            'card' => \App\Support\PaymentInstrument::card($detail['card']['network'] ?? null, $detail['card']['last4'] ?? null),
            'upi' => \App\Support\PaymentInstrument::upi($detail['vpa'] ?? null),
            'netbanking' => isset($detail['bank']) ? 'Net banking · '.$detail['bank'] : null,
            'wallet' => isset($detail['wallet']) ? 'Wallet · '.ucfirst((string) $detail['wallet']) : null,
            default => null,
        };

        return [
            'status' => 'SUCCESS',
            'gateway_txn_id' => $paymentId,
            'paid_amount' => number_format(((int) ($paid['amount'] ?? $json['amount_paid'] ?? $json['amount'] ?? 0)) / 100, 2, '.', ''),
            'payment_mode' => isset($detail['method']) ? strtoupper((string) $detail['method']) : ($paid['method'] ?? null),
            'bank_txn_id' => $detail['acquirer_data']['bank_transaction_id'] ?? $detail['acquirer_data']['upi_transaction_id'] ?? null,
            'bank_rrn' => $detail['acquirer_data']['rrn'] ?? null,
            'instrument' => $instrument,
            'raw' => $json,
        ];
    }

    public function verifyWebhook(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = (string) $this->company->razorpay_webhook_secret;

        return $secret !== '' && is_string($signatureHeader) && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signatureHeader);
    }

    public function refund(Payment $payment, string $amount, string $reason): array
    {
        $res = $this->http()->post('/payments/'.rawurlencode((string) $payment->gateway_txn_id).'/refund', [
            'amount' => SabPaisaGateway::paise($amount), 'speed' => 'normal', 'notes' => ['reason' => Str::limit($reason, 250, ''), 'payment_uuid' => $payment->uuid],
        ]);
        $json = (array) $res->json();
        if (! $res->successful()) {
            throw new GatewayException(self::errorMessage($json, $res->status()), $json);
        }

        return ['refund_id' => isset($json['id']) ? (string) $json['id'] : null, 'status' => self::refundStatusOf($json), 'raw' => $json];
    }

    public function refundStatus(string $refundId): array
    {
        $res = $this->http()->get('/refunds/'.rawurlencode($refundId));
        $json = (array) $res->json();
        if (! $res->successful()) {
            throw new GatewayException(self::errorMessage($json, $res->status()), $json);
        }

        return ['status' => self::refundStatusOf($json), 'bank_refund_id' => $json['acquirer_data']['arn'] ?? null, 'raw' => $json];
    }

    private static function refundStatusOf(array $json): string
    {
        return match (strtolower((string) ($json['status'] ?? 'pending'))) { 'processed' => 'SUCCESS', 'failed' => 'FAILED', default => 'INITIATED' };
    }

    private static function errorMessage(array $json, int $status): string
    {
        return 'Razorpay: '.($json['error']['description'] ?? $json['error']['code'] ?? "HTTP {$status}");
    }
}
