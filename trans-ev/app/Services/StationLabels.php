<?php

namespace App\Services;

use App\Models\Booking;

/** Plain-language labels shared by the Staff Console, admin and customer pages. */
class StationLabels
{
    /** One sentence describing the final-bill outcome of a prepaid booking. */
    public static function trueUp(Booking $b): ?string
    {
        $diff = number_format(abs((float) $b->trueup_amount), 2);

        return match ($b->trueup_status) {
            'none' => 'Prepaid amount covers the energy delivered.',
            'refund_pending' => "Refund of ₹{$diff} being started.",
            'refunded' => "₹{$diff} refunded for energy not used (credit note issued).",
            'refund_failed' => "Refund of ₹{$diff} failed; finance will retry.",
            'absorbed' => "₹{$diff} extra energy absorbed by the operator (nothing to collect).",
            'due' => '₹'.number_format((float) $b->balance_due, 2).' balance to collect for extra energy.',
            'paid' => '₹'.number_format((float) $b->balance_due, 2).' balance paid (debit note issued).',
            default => null,
        };
    }
}
