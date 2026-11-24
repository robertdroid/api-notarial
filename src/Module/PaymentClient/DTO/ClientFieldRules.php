<?php

declare(strict_types=1);

namespace App\Module\PaymentClient\DTO;

final class ClientFieldRules
{
    public const FULL_NAME_MAX = 250;
    public const PHONE_MAX = 20;
    public const EMAIL_MAX = 250;

    public static function fullName(mixed $value): ?string
    {
        if (!is_string($value)) {
            return 'Full name must be a string';
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return 'Full name is required';
        }

        if (mb_strlen($trimmed) > self::FULL_NAME_MAX) {
            return 'Full name must not exceed ' . self::FULL_NAME_MAX . ' characters';
        }

        if (self::hasUnsupportedChars($trimmed)) {
            return 'Full name contains unsupported characters';
        }

        return null;
    }

    /** null is accepted and means "empty" (ADR-003). */
    public static function phone(mixed $value): ?string
    {
        if ($value !== null && !is_string($value)) {
            return 'Phone must be a string';
        }

        $trimmed = self::normalizeOptional($value);

        if (mb_strlen($trimmed) > self::PHONE_MAX) {
            return 'Phone must not exceed ' . self::PHONE_MAX . ' characters';
        }

        if (self::hasUnsupportedChars($trimmed)) {
            return 'Phone contains unsupported characters';
        }

        return null;
    }

    /** null is accepted and means "empty" (ADR-003). */
    public static function email(mixed $value): ?string
    {
        if ($value !== null && !is_string($value)) {
            return 'Email must be a string';
        }

        $trimmed = self::normalizeOptional($value);

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > self::EMAIL_MAX) {
            return 'Email must not exceed ' . self::EMAIL_MAX . ' characters';
        }

        // Rejects any non-ASCII character, so 4-byte chars are covered too.
        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
            return 'Email format is invalid';
        }

        return null;
    }

    public static function status(mixed $value): ?string
    {
        return is_int($value) && in_array($value, [1, 2], true) ? null : 'Status must be 1 or 2';
    }

    /** Call only after the matching rule returned null. */
    public static function normalizeOptional(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function hasUnsupportedChars(string $value): bool
    {
        // Columns are utf8mb3. preg_match returns false on invalid UTF-8, which is also rejected.
        return preg_match('/[\x{10000}-\x{10FFFF}]/u', $value) !== 0;
    }
}
