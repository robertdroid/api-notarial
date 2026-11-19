<?php

declare(strict_types=1);

namespace App\Module\Receipt\Service;

/**
 * Locale-independent formatting for receipts (never depends on setlocale).
 */
final class MoneyFormatter
{
    public static function money(float $amount): string
    {
        return '$' . number_format($amount, 2, '.', ',');
    }

    /** Y-m-d (or Y-m-d H:i:s) to dd/mm/yyyy; returns $fallback when empty or not a valid date. */
    public static function date(?string $ymd, string $fallback = '—'): string
    {
        if ($ymd === null || $ymd === '') {
            return $fallback;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
        if ($date === false || $date->format('Y-m-d') !== substr($ymd, 0, 10)) {
            return $fallback;
        }

        return $date->format('d/m/Y');
    }
}
