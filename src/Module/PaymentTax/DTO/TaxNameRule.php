<?php

declare(strict_types=1);

namespace App\Module\PaymentTax\DTO;

final class TaxNameRule
{
    public const MAX_LENGTH = 500;

    /**
     * Returns the error message, or null when the value is valid.
     */
    public static function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return 'Tax name must be a string';
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return 'Tax name is required';
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            return 'Tax name must not exceed ' . self::MAX_LENGTH . ' characters';
        }

        // Column is utf8mb3: 4-byte characters (emoji) are not supported.
        // preg_match returns false on invalid UTF-8, which is also rejected.
        if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $trimmed) !== 0) {
            return 'Tax name contains unsupported characters';
        }

        return null;
    }
}
