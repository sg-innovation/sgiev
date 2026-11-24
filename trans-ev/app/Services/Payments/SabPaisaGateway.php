<?php

namespace App\Services\Payments;

use App\Models\Company;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * SabPaisa Payment Gateway (PG 3.0 REST API, https://devdocs.sabpaisa.in).
 * One instance per operating company: money goes to that company's merchant account.
 *
 * - Requests: JSON with an HMAC-SHA256 checksum of merchantId|merchantTxnId|amount|currency|timestamp (hex).
 * - Return URL: signed query params (HMAC-SHA256 of the sorted key=value pairs joined with "|", hex).
 * - Webhooks: header "X-SabPaisa-Signature: {timestamp_ms}.{base64(HMAC-SHA256(timestamp.rawBody))}".
 * Amounts are sent in paise; SabPaisa reports paid amounts in rupees.
 */
class SabPaisaGateway implements PaymentGateway
{
    public function __construct(private readonly Company $company) {}

    public function environment(): string
    {
        return $this->company->sabpaisa_env === 'production' ? 'production' : 'staging';
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('payments.sabpaisa.base_urls.'.$this->environment()))
            ->acceptJson()
            ->asJson()
            ->timeout(config('payments.sabpaisa.timeout'))
            ->withHeaders(['X-Api-Key' => (string) $this->company->sabpaisa_api_key]);
    }

    private function secret(): string
    {
        return (string) $this->company->sabpaisa_secret_key;
    }

    public static function paise(string $rupees): int
    {
        return (int) bcmul($rupees, '100', 0);
    }

    public function createSession(Payment $payment, array $customer, string $returnUrl, string $description): array
    {
        $amount = self::paise((string) $payment->amount);
        $timestamp = now()->timestamp;
        $merchantId = (string) $this->company->sabpaisa_merchant_id;
        $checksum = hash_hmac('sha256', "{$merchantId}|{$payment->merchant_txn_id}|{$amount}|INR|{$timestamp}", $this->secret());

        $res = $this->http()->post('/api/v2/payments', [
            'merchantId' => $merchantId,
            'merchantTxnId' => $payment->merchant_txn_id,
            'amount' => $amount,
            'currency' => 'INR',
            'customerName' => self::cleanName($customer['name']),
            'customerEmail' => $customer['email'],
            'customerPhone' => $customer['phone'],
            'returnUrl' => $returnUrl,
            'description' => Str::limit($description, 480, ''),
            'udf1' => $payment->uuid,
            'checksum' => $checksum,
            'timestamp' => $timestamp,
        ]);

        $json = (array) $res->json();
        if (! $res->successful() || empty($json['checkoutUrl'])) {
            throw new GatewayException(self::errorMessage($json, $res->status()), $json);
        }

        return [
            'payment_id' => (string) ($json['paymentId'] ?? ''),
            'checkout_url' => (string) $json['checkoutUrl'],
            'client_secret' => $json['clientSecret'] ?? null,
            'expires_at' => $json['expiresAt'] ?? null,
            'raw' => $json,
        ];
    }

    public function redirectUrl(Payment $payment): string
    {
        $url = (string) $payment->checkout_url;

        return $payment->client_secret
            ? $url.(str_contains($url, '?') ? '&' : '?').'clientSecret='.urlencode($payment->client_secret)
            : $url;
    }

    public function verifyReturn(array $query): bool
    {
        $signature = $query['signature'] ?? null;
        $fields = ['transaction_id', 'merchant_txn_id', 'status', 'amount', 'paid_amount', 'payment_mode', 'timestamp'];
        if (! is_string($signature) || array_diff($fields, array_keys($query))) {
            return false;
        }
        $data = collect($query)->only($fields)->sortKeys()->map(fn ($v, $k) => "{$k}={$v}")->implode('|');

        return hash_equals(hash_hmac('sha256', $data, $this->secret()), strtolower($signature));
    }

    public function enquiry(Payment $payment): array
    {
        $res = $this->http()->post('/api/v2/payments/enquiry', [
            'clientCode' => (string) $this->company->sabpaisa_merchant_id,
            'merchantTxnId' => $payment->merchant_txn_id,
        ]);
        $json = (array) $res->json();

        if ($res->status() === 404) {
            // Customer never reached the checkout page: nothing to confirm yet.
            return ['status' => 'PENDING', 'gateway_txn_id' => null, 'paid_amount' => null, 'payment_mode' => null, 'bank_txn_id' => null, 'bank_rrn' => null, 'raw' => $json];
        }
        if (! $res->successful()) {
            throw new GatewayException(self::errorMessage($json, $res->status()), $json);
        }

        return [
            'status' => strtoupper((string) ($json['status'] ?? 'PENDING')),
            'gateway_txn_id' => isset($json['txnId']) ? (string) $json['txnId'] : null,
            'paid_amount' => isset($json['paidAmount']) ? number_format((float) $json['paidAmount'], 2, '.', '') : null,
            'payment_mode' => $json['paymentMode'] ?? null,
            'bank_txn_id' => $json['bankTxnId'] ?? null,
            'bank_rrn' => $json['bankRrn'] ?? null,
            'raw' => $json,
        ];
    }

    public function verifyWebhook(string $rawBody, ?string $signatureHeader): bool
    {
        if (! $signatureHeader || ! str_contains($signatureHeader, '.')) {
            return false;
        }
        [$timestamp, $signature] = explode('.', $signatureHeader, 2);
        if (! ctype_digit($timestamp) || abs(now()->getTimestampMs() - (int) $timestamp) > (\App\Support\BusinessRules::int('payments.webhook_tolerance_seconds') * 1000)) {
            return false;
        }
        $secret = (string) ($this->company->sabpaisa_webhook_secret ?: $this->company->sabpaisa_secret_key);
        $expected = base64_encode(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret, true));

        return hash_equals($expected, $signature);
    }

    public function refund(Payment $payment, string $amount, string $reason): array
    {
        $res = $this->http()->withHeaders(['X-Merchant-Id' => (string) $this->company->sabpaisa_merchant_id])
            ->post('/api/v2/refunds', [
                'txnId' => $payment->gateway_txn_id,
                'amount' => self::paise($amount),
                'reason' => Str::limit($reason, 480, ''),
            ]);
        $json = (array) $res->json();
        if (! $res->successful()) {
            throw new GatewayException(self::errorMessage($json, $res->status()), $json);
        }
        $data = (array) ($json['data'] ?? $json);

        return ['refund_id' => isset($data['refundId']) ? (string) $data['refundId'] : null, 'status' => strtoupper((string) ($data['status'] ?? 'INITIATED')), 'raw' => $json];
    }

    public function refundStatus(string $refundId): array
    {
        $res = $this->http()->withHeaders(['X-Merchant-Id' => (string) $this->company->sabpaisa_merchant_id])
            ->get('/api/v2/refunds/'.rawurlencode($refundId));
        $json = (array) $res->json();
        if (! $res->successful()) {
            throw new GatewayException(self::errorMessage($json, $res->status()), $json);
        }
        $data = (array) ($json['data'] ?? $json);

        return ['status' => strtoupper((string) ($data['status'] ?? 'INITIATED')), 'bank_refund_id' => $data['bankRefundId'] ?? null, 'raw' => $json];
    }

    /** SabPaisa accepts 2–100 letters and spaces. */
    public static function cleanName(string $name): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z ]/', ' ', Str::ascii($name))) ?? '');

        return Str::limit(strlen($clean) >= 2 ? $clean : 'EV Customer', 100, '');
    }

    private static function errorMessage(array $json, int $status): string
    {
        $code = $json['code'] ?? $json['error'] ?? $json['errorCode'] ?? null;
        $msg = $json['message'] ?? null;

        return trim('SabPaisa error '.$status.($code ? " ({$code})" : '').($msg ? ": {$msg}" : ''));
    }
}
