<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\DTO;

final readonly class TransactionDetailItem
{
    public function __construct(
        public int $taxId,
        public int $quantity,
        public float $amount,
        public ?string $date,
        public string $comments,
    ) {
    }

    public function subtotal(): float
    {
        return round($this->quantity * $this->amount, 2);
    }
}
