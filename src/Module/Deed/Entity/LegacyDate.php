<?php

declare(strict_types=1);

namespace App\Module\Deed\Entity;

/**
 * The legacy system stores "no date" as 0000-00-00 (MariaDB is not running with NO_ZERO_DATE).
 */
final class LegacyDate
{
    private const ZERO_DATE = '0000-00-00';

    public static function toYmd(?string $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with($value, self::ZERO_DATE)) {
            return null;
        }

        return substr($value, 0, 10);
    }
}
