<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\Entity;

final class PaymentTransactionDetail
{
    public function __construct(
        public readonly int $taxId,
        public readonly string $taxName,
        public readonly int $quantity,
        public readonly float $amount,
        public readonly ?string $date,
        public readonly string $comments,
    ) {
    }

    /**
     * Tolerates legacy NULLs written by the v1 application.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            taxId: (int) $row['id_tax'],
            taxName: (string) ($row['tax_name'] ?? ''),
            quantity: (int) ($row['quantity'] ?? 1),
            amount: (float) ($row['amount'] ?? 0.0),
            date: isset($row['date']) ? substr((string) $row['date'], 0, 10) : null,
            comments: (string) ($row['comments'] ?? ''),
        );
    }
}
