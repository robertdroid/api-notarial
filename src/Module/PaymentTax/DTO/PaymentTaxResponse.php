<?php

declare(strict_types=1);

namespace App\Module\PaymentTax\DTO;

use App\Module\PaymentTax\Entity\PaymentTax;

final class PaymentTaxResponse
{
    /**
     * @return array{taxId: int, taxName: string, status: int}
     */
    public static function fromEntity(PaymentTax $tax): array
    {
        return [
            'taxId' => $tax->id,
            'taxName' => $tax->name,
            'status' => $tax->statusId,
        ];
    }

    /**
     * @param PaymentTax[] $taxes
     * @return array<int, array{taxId: int, taxName: string, status: int}>
     */
    public static function fromCollection(array $taxes): array
    {
        return array_map(fn (PaymentTax $tax) => self::fromEntity($tax), $taxes);
    }
}
