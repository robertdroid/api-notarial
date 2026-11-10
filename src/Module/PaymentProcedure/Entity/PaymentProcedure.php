<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedure\Entity;

final class PaymentProcedure
{
    public const STATUS_ACTIVE = 1;

    public function __construct(
        public readonly int $id,
        public readonly int $notaryId,
        public readonly string $name,
        public readonly int $statusId,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_procedure'],
            notaryId: (int) $row['id_notary'],
            name: (string) $row['procedure_name'],
            statusId: (int) ($row['id_status'] ?? self::STATUS_ACTIVE),
        );
    }
}
