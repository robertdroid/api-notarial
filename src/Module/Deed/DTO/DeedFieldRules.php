<?php

declare(strict_types=1);

namespace App\Module\Deed\DTO;

/**
 * Validation rules shared by the deed, participant and registration request DTOs.
 */
final class DeedFieldRules
{
    public const MAX_DEED_NUMBER = 50;
    public const MAX_BOOK = 100;
    public const MAX_DELIVERED_BY = 250;
    public const MAX_PARTICIPANT_NAME = 250;
    public const MAX_REGISTRATION_FIELD = 100;
    public const MAX_PARTICIPANTS = 50;
    public const MAX_REGISTRATIONS = 20;
    public const MAX_TEXT_BYTES = 65535;

    public static function positiveInt(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    public static function date(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        // The round-trip rejects 2024-02-30 (PHP would roll it over) and 2024-1-5
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Returns an error message, or null when valid. Length counts characters (varchar columns).
     */
    public static function string(mixed $value, int $maxChars, string $label): ?string
    {
        if (!is_string($value)) {
            return "$label must be a string";
        }

        if (mb_strlen($value) > $maxChars) {
            return "$label must not exceed $maxChars characters";
        }

        return null;
    }

    /**
     * Returns an error message, or null when valid. Length counts bytes (TEXT columns).
     */
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

    /** Trims and turns an empty result into null. */
    public static function normalizeOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
