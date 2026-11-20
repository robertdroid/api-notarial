<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\Service;

use App\Module\PaymentStatement\DTO\StatementLedgerRow;
use App\Module\PaymentStatement\Entity\ClientStatement;
use App\Module\PaymentStatement\Entity\LedgerRowKind;
use App\Module\Receipt\Entity\ReceiptType;

/**
 * Merges the charges and payments of a ClientStatement into one chronological ledger with a running
 * balance. Pure: no database, no clock, no I/O.
 *
 * It only orders and accumulates (in integer cents, like PaymentStatementService). The totals and the
 * final balance are never calculated here: they come from the ClientStatement.
 *
 * Not final only so tests can force an inconsistent ledger (see StatementPdfServiceTest).
 */
class StatementLedgerBuilder
{
    private const CONCEPT_SEPARATOR = ' – ';
    private const NO_CONCEPT = '—';
    private const DEFAULT_PAYMENT_CONCEPT = 'Abono';

    /** @return list<StatementLedgerRow> */
    public function build(ClientStatement $statement): array
    {
        $running = self::toCents($statement->pendingAmount);
        $rows = [];

        if ($statement->fromDate !== null) {
            $rows[] = new StatementLedgerRow(
                LedgerRowKind::Opening,
                null,
                '',
                'Saldo anterior',
                null,
                null,
                self::fromCents($running),
            );
        }

        foreach ($this->sortedItems($statement) as $item) {
            $cents = self::toCents($item['amount']);
            $isCharge = $item['kind'] === LedgerRowKind::Charge;
            $running += $isCharge ? $cents : -$cents;

            $rows[] = new StatementLedgerRow(
                $item['kind'],
                $item['date'],
                $item['folio'],
                $item['concept'],
                $isCharge ? $item['amount'] : null,
                $isCharge ? null : $item['amount'],
                self::fromCents($running),
            );
        }

        return $rows;
    }

    /**
     * Order: date (Y-m-d, not time), charge before payment, id. Undated payments go last.
     * The comparison is complete, so the result never depends on the sort being stable.
     *
     * @return list<array{kind: LedgerRowKind, rank: int, date: ?string, id: int, folio: string, concept: string, amount: float}>
     */
    private function sortedItems(ClientStatement $statement): array
    {
        $items = [];

        foreach ($statement->transactions as $transaction) {
            $items[] = [
                'kind' => LedgerRowKind::Charge,
                'rank' => 0,
                'date' => $transaction->date,
                'id' => $transaction->id,
                'folio' => ReceiptType::Transaction->folio($transaction->id),
                'concept' => self::transactionConcept($transaction->procedureName, $transaction->reference),
                'amount' => $transaction->total,
            ];
        }

        foreach ($statement->payments as $payment) {
            $comment = trim($payment->comment);

            $items[] = [
                'kind' => LedgerRowKind::Payment,
                'rank' => 1,
                'date' => $payment->date,
                'id' => $payment->id,
                'folio' => ReceiptType::Payment->folio($payment->id),
                'concept' => $comment !== '' ? $comment : self::DEFAULT_PAYMENT_CONCEPT,
                'amount' => $payment->amount,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return [$a['date'] === null, $a['date'] ?? '', $a['rank'], $a['id']]
                <=> [$b['date'] === null, $b['date'] ?? '', $b['rank'], $b['id']];
        });

        return $items;
    }

    private static function transactionConcept(string $procedure, string $reference): string
    {
        $parts = array_values(array_filter(
            [trim($procedure), trim($reference)],
            static fn (string $part): bool => $part !== ''
        ));

        return $parts === [] ? self::NO_CONCEPT : implode(self::CONCEPT_SEPARATOR, $parts);
    }

    private static function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private static function fromCents(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
