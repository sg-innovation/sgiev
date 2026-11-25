<?php

namespace App\Support;

/** Amount in words, Indian numbering (lakh, crore), as printed on Indian receipts and tax invoices. */
final class IndianNumber
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    /** 1234567.5 → "12,34,567.50" (Indian digit grouping). */
    public static function format(string|int|float|null $amount, int $decimals = 2): string
    {
        $n = number_format(abs((float) $amount), $decimals, '.', '');
        [$int, $frac] = array_pad(explode('.', $n), 2, '');
        if (strlen($int) > 3) {
            $int = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, -3)).','.substr($int, -3);
        }

        return ((float) $amount < 0 && (float) $n > 0 ? '-' : '').$int.($decimals > 0 ? '.'.$frac : '');
    }

    public static function rupeesInWords(string $amount): string
    {
        [$rupees, $paise] = array_pad(explode('.', number_format((float) $amount, 2, '.', '')), 2, '00');
        $words = 'Rupees '.(((int) $rupees) === 0 ? 'Zero' : self::words((int) $rupees));
        if ((int) $paise > 0) {
            $words .= ' and '.self::words((int) $paise).' Paise';
        }

        return $words.' Only';
    }

    private static function words(int $n): string
    {
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$unit, $name]) {
            if ($n >= $unit) {
                $parts[] = self::words(intdiv($n, $unit)).' '.$name;
                $n %= $unit;
            }
        }
        if ($n > 0) {
            $parts[] = $n < 20 ? self::ONES[$n] : trim(self::TENS[intdiv($n, 10)].' '.self::ONES[$n % 10]);
        }

        return implode(' ', $parts);
    }
}
