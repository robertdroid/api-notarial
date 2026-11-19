<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\Entity;

final class ClientStatement
{
    /**
     * @param list<StatementTransaction> $transactions
     * @param list<StatementPayment>     $payments
     */
    public function __construct(
        public readonly int $clientId,
        public readonly string $clientName,
        public readonly ?string $fromDate,
        public readonly ?string $toDate,
        public readonly array $transactions,
        public readonly array $payments,
        public readonly float $pendingAmount,
        public readonly float $totalCharges,
        public readonly float $totalPayments,
        public readonly float $balance,
    ) {
    }
}
