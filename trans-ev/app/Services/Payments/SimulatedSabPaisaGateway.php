<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * LOCAL DEVELOPMENT ONLY. Stands in for SabPaisa so the full flow can be tested without credentials:
 * the "checkout" is a local page with Success / Failure / Cancel buttons, results are signed like SabPaisa's
 * return URL, and enquiry/refund read the simulated state. Refuses to run outside APP_ENV=local or tests.
 */
class SimulatedSabPaisaGateway implements PaymentGateway
{
    public function __construct()
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The payment simulator is only available in local development.');
        }
    }

    public static function secret(): string
    {
        return hash('sha256', 'sabpaisa-simulator|'.config('app.key'));
    }

    public function environment(): string
    {
        return 'simulator';
    }

    public function createSession(Payment $payment, array $customer, string $returnUrl, string $description): array
    {
        $id = 'SIM_PAY_'.Str::upper(Str::random(10));
        Cache::put("sim-sabpaisa:{$payment->merchant_txn_id}", ['status' => 'PENDING', 'return_url' => $returnUrl, 'payment_id' => $id], now()->addDay());

        return [
            'payment_id' => $id,
            'checkout_url' => url('/dev/sabpaisa/checkout/'.$payment->uuid),
            'client_secret' => null,
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
            'raw' => ['simulated' => true, 'paymentId' => $id],
        ];
    }

    public function redirectUrl(Payment $payment): string
    {
        return (string) $payment->checkout_url;
    }

    /** Called by the simulator page: records the outcome and returns the signed return-URL query. */
    public static function complete(Payment $payment, string $status): array
    {
        $state = Cache::get("sim-sabpaisa:{$payment->merchant_txn_id}", []);
        $txn = 'SIM_TXN_'.Str::upper(Str::random(8));
        $mode = 'UPI';
        Cache::put("sim-sabpaisa:{$payment->merchant_txn_id}", array_merge($state, [
            'status' => $status, 'txn_id' => $txn, 'mode' => $mode,
            'paid' => $status === 'SUCCESS' ? number_format((float) $payment->amount, 2, '.', '') : '0.00',
        ]), now()->addDay());

        $query = [
            'transaction_id' => $txn,
            'merchant_txn_id' => $payment->merchant_txn_id,
            'status' => $status,
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'paid_amount' => $status === 'SUCCESS' ? number_format((float) $payment->amount, 2, '.', '') : '0.00',
            'payment_mode' => $mode,
            'timestamp' => (string) now()->getTimestampMs(),
        ];
        $data = collect($query)->sortKeys()->map(fn ($v, $k) => "{$k}={$v}")->implode('|');
        $query['signature'] = hash_hmac('sha256', $data, self::secret());

        return [$state['return_url'] ?? route('bookings.index'), $query];
    }

    public function verifyReturn(array $query): bool
    {
        $sig = $query['signature'] ?? '';
        unset($query['signature']);
        $data = collect($query)->sortKeys()->map(fn ($v, $k) => "{$k}={$v}")->implode('|');

        return is_string($sig) && hash_equals(hash_hmac('sha256', $data, self::secret()), $sig);
    }

    public function enquiry(Payment $payment): array
    {
        $s = Cache::get("sim-sabpaisa:{$payment->merchant_txn_id}", ['status' => 'PENDING']);

        return [
            'status' => $s['status'],
            'gateway_txn_id' => $s['txn_id'] ?? null,
            'paid_amount' => $s['paid'] ?? null,
            'payment_mode' => $s['mode'] ?? null,
            'bank_txn_id' => isset($s['txn_id']) ? 'SIMBANK'.substr($s['txn_id'], -6) : null,
            'bank_rrn' => null,
            'raw' => ['simulated' => true] + $s,
        ];
    }

    public function verifyWebhook(string $rawBody, ?string $signatureHeader): bool
    {
        return false; // the simulator does not send webhooks
    }

    public function refund(Payment $payment, string $amount, string $reason): array
    {
        return ['refund_id' => 'SIM_RFND_'.Str::upper(Str::random(8)), 'status' => 'SUCCESS', 'raw' => ['simulated' => true, 'amount' => $amount]];
    }

    public function refundStatus(string $refundId): array
    {
        return ['status' => 'SUCCESS', 'bank_refund_id' => 'SIMBANK-'.$refundId, 'raw' => ['simulated' => true]];
    }
}
