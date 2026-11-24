<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\DTO;

use App\Module\PaymentStatement\Entity\BalanceStatus;
use App\Module\Receipt\DTO\ReceiptClient;
use App\Module\Receipt\DTO\ReceiptNotary;
use App\Module\Receipt\Entity\ReceiptType;
use App\Module\Receipt\Service\MoneyFormatter;

/** Everything the statement PDF shows, already resolved. Immutable: the renderer only presents it. */
final class StatementPdfData
{
    /**
     * @param string|null                $fromDate Y-m-d
     * @param string|null                $toDate   Y-m-d
     * @param list<StatementLedgerRow>   $rows
     * @param string|null                $amountInWords Null when there is no debt
     */
    public function __construct(
        public readonly int $clientId,
        public readonly ReceiptNotary $notary,
        public readonly ReceiptClient $client,
        public readonly ?string $fromDate,
        public readonly ?string $toDate,
        public readonly array $rows,
        public readonly bool $hasMovements,
        public readonly float $pendingAmount,
        public readonly float $totalCharges,
        public readonly float $totalPayments,
        public readonly float $balance,
        public readonly BalanceStatus $status,
        public readonly ?string $amountInWords,
        public readonly \DateTimeImmutable $generatedAt,
    ) {
    }

    public function periodLabel(): string
    {
        return match (true) {
            $this->fromDate !== null && $this->toDate !== null => sprintf(
                'Del %s al %s',
                MoneyFormatter::date($this->fromDate),
                MoneyFormatter::date($this->toDate)
            ),
            $this->fromDate !== null => 'Desde ' . MoneyFormatter::date($this->fromDate),
            $this->toDate !== null => 'Hasta ' . MoneyFormatter::date($this->toDate),
            default => 'Todos los movimientos',
        };
    }

    /** estado-de-cuenta-C-000005.pdf. Only [A-Za-z0-9._-]: Response::pdf() rejects anything else. */
    public function fileName(): string
    {
        // Spec 21 fixes the C-%06d shape for the client id; ReceiptType::Payment already formats it
        return 'estado-de-cuenta-' . ReceiptType::Payment->folio($this->clientId) . '.pdf';
    }
}
