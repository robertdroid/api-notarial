<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedureTax\DTO;

use App\Module\PaymentProcedureTax\Entity\PaymentProcedureTax;

final class ProcedureTaxResponse
{
    /**
     * @return array{procedureId: int, taxId: int, taxName: string, defaultValue: float}
     */
    public static function fromEntity(PaymentProcedureTax $tax): array
    {
        return [
            'procedureId' => $tax->procedureId,
            'taxId' => $tax->taxId,
            'taxName' => $tax->taxName,
            'defaultValue' => $tax->defaultValue,
        ];
    }

    /**
     * @param PaymentProcedureTax[] $taxes
     * @return array<int, array{procedureId: int, taxId: int, taxName: string, defaultValue: float}>
     */
    public static function fromCollection(array $taxes): array
    {
        return array_map(fn (PaymentProcedureTax $tax) => self::fromEntity($tax), $taxes);
    }
}
