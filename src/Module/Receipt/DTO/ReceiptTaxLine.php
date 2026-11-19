<?php

declare(strict_types=1);

namespace App\Module\Receipt\DTO;

final class ReceiptTaxLine
{
    public function __construct(
        public readonly int $quantity,
        public readonly string $concept,
        public readonly float $amount,
    ) {
    }
}
