<?php

declare(strict_types=1);

namespace App\Module\Payment\Entity;

final class Payment
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly int $notaryId,
        public readonly int $clientId,
        public readonly string $clientName,
        public readonly float $amount,
        public readonly ?string $paymentDate,
        public readonly string $comments,
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
            notaryId: (int) $row['id_notary'],
            clientId: (int) $row['id_client'],
            clientName: (string) ($row['client_name'] ?? ''),
            amount: (float) $row['amount'],
            // Legacy rows may have no date
            paymentDate: ($row['payment_date'] ?? null) !== null ? substr((string) $row['payment_date'], 0, 10) : null,
            comments: (string) ($row['comments'] ?? ''),
        );
    }
}
