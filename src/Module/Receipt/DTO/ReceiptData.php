<?php

declare(strict_types=1);

namespace App\Module\Receipt\DTO;

use App\Module\Receipt\Entity\ReceiptType;

/**
 * Everything a receipt shows, as domain values (no HTML, no presentation formatting).
 * Transaction-only fields are empty/zero on payment receipts.
 */
final class ReceiptData
{
    /**
     * @param string|null $date Y-m-d: transaction date, or payment date (null = unknown)
     * @param ReceiptTaxLine[] $taxLines
     */
    public function __construct(
        public readonly ReceiptType $type,
        public readonly int $id,
        public readonly string $uuid,
        public readonly ?string $date,
        public readonly ReceiptNotary $notary,
        public readonly ReceiptClient $client,
        public readonly float $total,
        public readonly string $amountInWords,
        public readonly bool $cancelled,
        public readonly \DateTimeImmutable $generatedAt,
        public readonly string $procedureName = '',
        public readonly string $reference = '',
        public readonly string $responsible = '',
        public readonly float $fee = 0.0,
        public readonly float $taxes = 0.0,
        public readonly array $taxLines = [],
        public readonly string $comments = '',
    ) {
    }

    public function folio(): string
    {
        return $this->type->folio($this->id);
    }

    /** e.g. recibo-tramite-T-000123.pdf */
    public function fileName(): string
    {
        return $this->type->fileSlug() . '-' . $this->folio() . '.pdf';
    }
}
