<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedureTax\DTO;

final class DefaultValueRule
{
    public const MESSAGE = 'Default value must be a number';

    public static function validate(mixed $value): ?string
    {
        // is_int/is_float exclude bool, numeric strings and arrays.
        return is_int($value) || is_float($value) ? null : self::MESSAGE;
    }

    /** Negatives, null and -0.0 all become 0.0. */
    public static function normalize(int|float|null $value): float
    {
        return max(0.0, (float) ($value ?? 0));
    }
}
