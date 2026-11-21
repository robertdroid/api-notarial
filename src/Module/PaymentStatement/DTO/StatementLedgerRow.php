<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\DTO;

use App\Module\PaymentStatement\Entity\LedgerRowKind;

/** One line of the ledger. Charge and payment are mutually exclusive; the opening row has neither. */
final class StatementLedgerRow
{
    /** @param string|null $date Y-m-d */
    public function __construct(
        public readonly LedgerRowKind $kind,
        public readonly ?string $date,
        public readonly string $folio,
        public readonly string $concept,
        public readonly ?float $charge,
        public readonly ?float $payment,
        public readonly float $balance,
    ) {
    }
}
