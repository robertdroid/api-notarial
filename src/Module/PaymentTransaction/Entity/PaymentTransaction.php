<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\Entity;

final class PaymentTransaction
{
    public const STATUS_ACTIVE = 1;

    /** @param PaymentTransactionDetail[] $details */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly int $notaryId,
        public readonly int $clientId,
        public readonly string $clientName,
        public readonly int $procedureId,
        public readonly string $procedureName,
        public readonly string $date,
        public readonly float $fee,
        public readonly float $taxes,
        public readonly float $total,
        public readonly string $reference,
        public readonly string $responsible,
        public readonly string $comments,
        public readonly int $statusId,
        public readonly array $details,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param PaymentTransactionDetail[] $details
     */
    public static function fromRow(array $row, array $details): self
    {
        return new self(
            id: (int) $row['id_transaction'],
            uuid: (string) $row['uuid'],
            notaryId: (int) $row['id_notary'],
            clientId: (int) $row['id_client'],
            clientName: (string) ($row['client_name'] ?? ''),
            procedureId: (int) $row['id_procedure'],
            procedureName: (string) ($row['procedure_name'] ?? ''),
            date: substr((string) $row['date'], 0, 10),
            fee: (float) $row['fee'],
            taxes: (float) $row['taxes'],
            total: (float) $row['total'],
            reference: (string) ($row['reference'] ?? ''),
            responsible: (string) ($row['responsible'] ?? ''),
            comments: (string) ($row['comments'] ?? ''),
            statusId: (int) ($row['id_status'] ?? self::STATUS_ACTIVE),
            details: $details,
        );
    }

    /** @return int[] */
    public function detailTaxIds(): array
    {
        return array_map(static fn (PaymentTransactionDetail $d): int => $d->taxId, $this->details);
    }
}
