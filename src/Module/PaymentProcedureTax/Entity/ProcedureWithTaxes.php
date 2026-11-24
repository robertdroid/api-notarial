<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedureTax\Entity;

final class ProcedureWithTaxes
{
    /**
     * @param PaymentProcedureTax[] $taxes
     */
    public function __construct(
        public readonly int $procedureId,
        public readonly string $procedureName,
        public readonly array $taxes,
    ) {
    }
}
