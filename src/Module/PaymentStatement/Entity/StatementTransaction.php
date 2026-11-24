<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\Entity;

final class StatementTransaction
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $date,
        public readonly string $procedureName,
        public readonly string $reference,
        public readonly float $fee,
        public readonly float $taxes,
        public readonly float $total,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_transaction'],
            uuid: (string) $row['uuid'],
            date: substr((string) $row['date'], 0, 10),
            // LEFT JOIN: the procedure may have been deleted
            procedureName: (string) ($row['procedure_name'] ?? ''),
            reference: (string) ($row['reference'] ?? ''),
            fee: (float) $row['fee'],
            taxes: (float) $row['taxes'],
            total: (float) $row['total'],
        );
    }
}
