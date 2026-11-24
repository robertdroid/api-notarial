<?php

declare(strict_types=1);

namespace App\Module\User\Entity;

/**
 * Module access codes stored as a CSV in usuarios_notaria.user_access.
 * The order of the cases defines the canonical CSV order.
 */
enum AccessCode: string
{
    case Deeds = 'ESC';
    case Payments = 'PAG';

    /**
     * @return string[] in canonical order
     */
    public static function values(): array
    {
        return array_map(static fn (self $code): string => $code->value, self::cases());
    }

    /**
     * @param string[] $codes already validated
     */
    public static function toCsv(array $codes): string
    {
        return implode(',', array_intersect(self::values(), $codes));
    }

    /**
     * Does not validate: unknown codes are returned as stored.
     *
     * @return string[]
     */
    public static function fromCsv(?string $csv): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) $csv)),
            static fn (string $code): bool => $code !== ''
        ));
    }
}
