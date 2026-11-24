<?php

declare(strict_types=1);

namespace App\Module\PaymentTax\Entity;

final class PaymentTax
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
            id: (int) $row['id_tax'],
            notaryId: (int) $row['id_notary'],
            name: (string) ($row['tax_name'] ?? ''),
            statusId: (int) ($row['id_status'] ?? self::STATUS_ACTIVE),
        );
    }
}
