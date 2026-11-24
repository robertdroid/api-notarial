<?php

declare(strict_types=1);

namespace App\Module\Payment\DTO;

use App\Shared\Uuid\ClientUuid;

final class PaymentFieldRules
{
    public const MAX_MONEY = 999999999.99;
    public const MAX_TEXT_BYTES = 65535;

    public static function positiveInt(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    /** Returns an error message, or null when valid. */
    public static function uuid(mixed $value): ?string
    {
        return ClientUuid::validate($value);
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

    /** Returns an error message, or null when valid. Only int/float: a numeric string is rejected. */
    public static function amount(mixed $value): ?string
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            return 'Amount must be a number';
        }

        if ($value <= 0) {
            return 'Amount must be greater than zero';
        }

        if ($value > self::MAX_MONEY) {
            return 'Amount is too large';
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
