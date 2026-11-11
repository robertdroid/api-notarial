<?php

declare(strict_types=1);

namespace App\Module\User\DTO;

use App\Module\User\Entity\AccessCode;

final class UserFieldRules
{
    public const USERNAME_FORMAT_MESSAGE = 'Username must be 3 to 20 characters (letters, digits, . or _)';
    public const PASSWORD_MIN = 8;
    // bcrypt silently ignores anything past 72 bytes
    public const PASSWORD_MAX_BYTES = 72;
    public const NAME_MAX = 150;

    private const USERNAME_PATTERN = '/\A[A-Za-z0-9._]{3,20}\z/';

    /** Not trimmed and case preserved: the login is an exact match. */
    public static function username(mixed $value): ?string
    {
        if (!is_string($value)) {
            return 'Username must be a string';
        }

        return preg_match(self::USERNAME_PATTERN, $value) === 1 ? null : self::USERNAME_FORMAT_MESSAGE;
    }

    /** Not trimmed: whitespace is a legitimate password character. */
    public static function password(mixed $value): ?string
    {
        if (!is_string($value)) {
            return 'Password must be a string';
        }

        // password_hash() throws ValueError on a NUL byte
        if (str_contains($value, "\0")) {
            return 'Password contains invalid characters';
        }

        if (mb_strlen($value) < self::PASSWORD_MIN) {
            return 'Password must be at least ' . self::PASSWORD_MIN . ' characters';
        }

        if (strlen($value) > self::PASSWORD_MAX_BYTES) {
            return 'Password must not exceed ' . self::PASSWORD_MAX_BYTES . ' bytes';
        }

        return null;
    }

    public static function requiredName(mixed $value, string $label): ?string
    {
        if (!is_string($value)) {
            return $label . ' must be a string';
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $label . ' is required';
        }

        return self::nameLengthAndChars($trimmed, $label);
    }

    /** null and '' are accepted and mean "empty". */
    public static function optionalName(mixed $value, string $label): ?string
    {
        if ($value !== null && !is_string($value)) {
            return $label . ' must be a string';
        }

        return self::nameLengthAndChars(self::normalizeOptional($value), $label);
    }

    public static function access(mixed $value): ?string
    {
        if (!is_array($value) || !array_is_list($value)) {
            return 'Access must be an array';
        }

        if ($value === []) {
            return 'At least one access code is required';
        }

        foreach ($value as $code) {
            if (!is_string($code) || !in_array($code, AccessCode::values(), true)) {
                return 'Access codes must be one of: ' . implode(', ', AccessCode::values());
            }
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

    private static function nameLengthAndChars(string $trimmed, string $label): ?string
    {
        if (mb_strlen($trimmed) > self::NAME_MAX) {
            return $label . ' must not exceed ' . self::NAME_MAX . ' characters';
        }

        // Columns are utf8mb3. preg_match returns false on invalid UTF-8, which is also rejected.
        if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $trimmed) !== 0) {
            return $label . ' contains unsupported characters';
        }

        return null;
    }
}
