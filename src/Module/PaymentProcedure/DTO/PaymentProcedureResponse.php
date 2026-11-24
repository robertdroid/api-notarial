<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedure\DTO;

use App\Module\PaymentProcedure\Entity\PaymentProcedure;

final class PaymentProcedureResponse
{
    /**
     * @return array{procedureId: int, procedureName: string, status: int}
     */
    public static function fromEntity(PaymentProcedure $procedure): array
    {
        return [
            'procedureId' => $procedure->id,
            'procedureName' => $procedure->name,
            'status' => $procedure->statusId,
        ];
    }

    /**
     * @param PaymentProcedure[] $procedures
     * @return array<int, array{procedureId: int, procedureName: string, status: int}>
     */
    public static function fromCollection(array $procedures): array
    {
        return array_map(fn (PaymentProcedure $procedure) => self::fromEntity($procedure), $procedures);
    }
}
