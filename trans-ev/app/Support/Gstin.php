<?php

namespace App\Support;

/** Indian GSTIN: 2-digit state + PAN + entity number + 'Z' + check character (GSTN mod-36 checksum). */
final class Gstin
{
    private const CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public const PATTERN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    public static function normalise(?string $gstin): ?string
    {
        $g = strtoupper(preg_replace('/\s+/', '', (string) $gstin));

        return $g === '' ? null : $g;
    }

    public static function checkCharacter(string $first14): string
    {
        $sum = 0;
        foreach (str_split(strtoupper(substr($first14, 0, 14))) as $i => $c) {
            $p = strpos(self::CHARS, $c) * ($i % 2 === 0 ? 1 : 2);
            $sum += intdiv($p, 36) + $p % 36;
        }

        return self::CHARS[(36 - $sum % 36) % 36];
    }

    public static function valid(?string $gstin): bool
    {
        $g = self::normalise($gstin);

        return $g !== null && preg_match(self::PATTERN, $g) === 1 && self::checkCharacter($g) === $g[14];
    }

    public static function pan(string $gstin): string
    {
        return substr(self::normalise($gstin) ?? '', 2, 10);
    }
}
