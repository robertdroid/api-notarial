<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedureTax\DTO;

use App\Module\PaymentProcedureTax\Entity\PaymentProcedureTax;
use App\Module\PaymentProcedureTax\Entity\ProcedureWithTaxes;

final class ProcedureWithTaxesResponse
{
    /**
     * @return array{procedureId: int, procedureName: string, taxes: list<array{taxId: int, taxName: string, defaultValue: float}>}
     */
    public static function fromEntity(ProcedureWithTaxes $procedure): array
    {
        return [
            'procedureId' => $procedure->procedureId,
            'procedureName' => $procedure->procedureName,
            'taxes' => array_map(
                static fn (PaymentProcedureTax $tax): array => [
                    'taxId' => $tax->taxId,
                    'taxName' => $tax->taxName,
                    'defaultValue' => $tax->defaultValue,
                ],
                array_values($procedure->taxes),
            ),
        ];
    }

    /**
     * @param ProcedureWithTaxes[] $procedures
     * @return list<array{procedureId: int, procedureName: string, taxes: list<array{taxId: int, taxName: string, defaultValue: float}>}>
     */
    public static function fromCollection(array $procedures): array
    {
        return array_map(self::fromEntity(...), array_values($procedures));
    }
}
