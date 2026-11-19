<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\Entity;

final class StatementPayment
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly ?string $date,
        public readonly string $comment,
        public readonly float $amount,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_payment'],
            uuid: (string) $row['uuid'],
            // Legacy rows may have no date
            date: ($row['payment_date'] ?? null) !== null ? substr((string) $row['payment_date'], 0, 10) : null,
            comment: (string) ($row['comments'] ?? ''),
            amount: (float) $row['amount'],
        );
    }
}
