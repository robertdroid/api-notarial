<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\Service;

use App\Module\PaymentClient\Repository\PaymentClientRepository;
use App\Module\PaymentStatement\DTO\StatementQuery;
use App\Module\PaymentStatement\Entity\ClientStatement;
use App\Module\PaymentStatement\Entity\StatementPayment;
use App\Module\PaymentStatement\Entity\StatementTransaction;
use App\Module\PaymentStatement\Repository\StatementRepository;

final class PaymentStatementService
{
    public function __construct(
        private readonly PaymentClientRepository $clientRepository,
        private readonly StatementRepository $statementRepository,
    ) {
    }

    /**
     * Returns null when the client does not exist in the notary. The client status is not checked:
     * an inactive client can still be consulted.
     *
     * Totals are computed in integer cents to avoid float drift.
     */
    public function build(int $clientId, int $notaryId, StatementQuery $query): ?ClientStatement
    {
        $client = $this->clientRepository->findByIdAndNotary($clientId, $notaryId);
        if ($client === null) {
            return null;
        }

        $from = $query->fromBoundary();
        $toExclusive = $query->toExclusiveBoundary();

        $transactions = $this->statementRepository->findActiveTransactions($notaryId, $clientId, $from, $toExclusive);
        $payments = $this->statementRepository->findPayments($notaryId, $clientId, $from, $toExclusive);

        $charges = 0;
        foreach ($transactions as $transaction) {
            $charges += self::toCents($transaction->total);
        }

        $received = 0;
        foreach ($payments as $payment) {
            $received += self::toCents($payment->amount);
        }

        $pending = 0;
        if ($from !== null) {
            $pending = self::toCents($this->statementRepository->sumActiveTransactionsBefore($notaryId, $clientId, $from))
                - self::toCents($this->statementRepository->sumPaymentsBefore($notaryId, $clientId, $from));
        }

        return new ClientStatement(
            clientId: $client->id,
            clientName: $client->fullName,
            fromDate: $query->from,
            toDate: $query->to,
            transactions: array_map(self::roundTransaction(...), $transactions),
            payments: array_map(self::roundPayment(...), $payments),
            pendingAmount: self::fromCents($pending),
            totalCharges: self::fromCents($charges),
            totalPayments: self::fromCents($received),
            balance: self::fromCents($pending + $charges - $received),
        );
    }

    private static function roundTransaction(StatementTransaction $t): StatementTransaction
    {
        return new StatementTransaction(
            $t->id,
            $t->uuid,
            $t->date,
            $t->procedureName,
            $t->reference,
            round($t->fee, 2),
            round($t->taxes, 2),
            round($t->total, 2),
        );
    }

    private static function roundPayment(StatementPayment $p): StatementPayment
    {
        return new StatementPayment($p->id, $p->uuid, $p->date, $p->comment, round($p->amount, 2));
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
