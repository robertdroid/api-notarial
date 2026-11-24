<?php

declare(strict_types=1);

namespace App\Module\PaymentClient\Entity;

final class PaymentClient
{
    public const STATUS_ACTIVE = 1;

    public function __construct(
        public readonly int $id,
        public readonly int $notaryId,
        public readonly string $fullName,
        public readonly string $phone,
        public readonly string $email,
        public readonly int $statusId,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_client'],
            notaryId: (int) $row['id_notary'],
            fullName: (string) $row['full_name'],
            phone: (string) $row['phone'],
            email: (string) $row['email'],
            statusId: (int) ($row['id_status'] ?? self::STATUS_ACTIVE),
        );
    }
}
