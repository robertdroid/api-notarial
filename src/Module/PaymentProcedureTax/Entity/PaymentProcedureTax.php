<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedureTax\Entity;

final class PaymentProcedureTax
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_INACTIVE = 2;

    public function __construct(
        public readonly int $procedureId,
        public readonly int $taxId,
        public readonly string $taxName,
        public readonly float $defaultValue,
        public readonly int $statusId,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            procedureId: (int) $row['id_procedure'],
            taxId: (int) $row['id_tax'],
            taxName: (string) ($row['tax_name'] ?? ''),
            defaultValue: (float) $row['default_value'],
            statusId: (int) ($row['id_status'] ?? self::STATUS_ACTIVE),
        );
    }
}
