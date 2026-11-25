<?php

namespace App\Support;

/**
 * Masked payment method for receipts: "VISA •••• 4242", "UPI ra•••@okicici", "Net banking · HDFC", "Wallet · Paytm".
 * Only the last four card digits and the first two letters of a UPI ID are ever kept.
 */
final class PaymentInstrument
{
    public static function card(?string $network, ?string $last4): ?string
    {
        $last4 = preg_replace('/\D/', '', (string) $last4);
        if (strlen($last4) < 4) {
            return null;
        }

        return trim(strtoupper((string) $network).' •••• '.substr($last4, -4));
    }

    public static function upi(?string $vpa): ?string
    {
        $vpa = strtolower(trim((string) $vpa));
        if (! preg_match('/^([a-z0-9._\-]+)@([a-z0-9.\-]+)$/', $vpa, $m)) {
            return null;
        }

        return 'UPI '.substr($m[1], 0, 2).'•••@'.$m[2];
    }

    /** Best effort from whatever a gateway returns: a VPA, or a card number already masked by the gateway. */
    public static function fromAny(?string $value, ?string $network = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (str_contains($value, '@')) {
            return self::upi($value);
        }
        $digits = preg_replace('/\D/', '', $value);

        return strlen($digits) >= 4 ? self::card($network, substr($digits, -4)) : null;
    }
}
