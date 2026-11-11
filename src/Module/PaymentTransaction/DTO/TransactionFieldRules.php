<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\DTO;

final class TransactionFieldRules
{
    public const MAX_DETAILS = 50;
    public const MAX_QUANTITY = 9999;
    public const MAX_MONEY = 999999999.99;
    public const MAX_TEXT_BYTES = 65535;
    public const UUID_ERROR = 'UUID must be 1 to 20 characters (letters, digits, - or _)';

    public static function positiveInt(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    /** Returns an error message, or null when valid. No trim and no case change (utf8mb3_bin column). */
    public static function uuid(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $value) !== 1) {
            return self::UUID_ERROR;
        }

        return null;
    }

    public static function date(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        // The round-trip rejects 2024-02-30 (PHP would roll it over to March 1st) and 2024-1-5
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** Returns an error message, or null when valid. $label e.g. "Fee", "Detail amount". */
    public static function money(mixed $value, string $label): ?string
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            return "$label must be a number";
        }

        if ($value < 0) {
            return "$label cannot be negative";
        }

        if ($value > self::MAX_MONEY) {
            return "$label is too large";
        }

        return null;
    }

    /** Returns an error message, or null when valid. */
    public static function quantity(mixed $value): ?string
    {
        if (!is_int($value)) {
            return 'Detail quantity must be an integer';
        }

        if ($value < 1) {
            return 'Detail quantity must be at least 1';
        }

        if ($value > self::MAX_QUANTITY) {
            return 'Detail quantity is too large';
        }

        return null;
    }

    /** Returns an error message, or null when valid. Length is measured in bytes (TEXT column). */
    public static function text(mixed $value, string $label): ?string
    {
        if (!is_string($value)) {
            return "$label must be a string";
        }

        if (strlen($value) > self::MAX_TEXT_BYTES) {
            return "$label is too long";
        }

        return null;
    }
}
