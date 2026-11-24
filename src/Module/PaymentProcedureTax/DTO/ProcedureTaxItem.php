<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedureTax\DTO;

final readonly class ProcedureTaxItem
{
    public function __construct(
        public int $taxId,
        public float $defaultValue,
    ) {
    }
}
