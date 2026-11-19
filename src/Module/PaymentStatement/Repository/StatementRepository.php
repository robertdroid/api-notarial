<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\Repository;

use App\Module\PaymentStatement\Entity\StatementPayment;
use App\Module\PaymentStatement\Entity\StatementTransaction;
use PDO;

/**
 * Read-only data access for the client statement. Every query is scoped by id_notary and id_client.
 * Dates arrive as ready-made datetime boundaries: [$from, $toExclusive).
 */
class StatementRepository
{
    private const STATUS_ACTIVE = 1;

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Active transactions only (cancelled ones never count).
     *
     * @return list<StatementTransaction>
     */
    public function findActiveTransactions(int $notaryId, int $clientId, ?string $from, ?string $toExclusive): array
    {
        $params = ['notaryId' => $notaryId, 'clientId' => $clientId];

        $sql = 'SELECT t.id_transaction, t.uuid, t.reference, t.`date`, t.fee, t.taxes, t.total, p.procedure_name
                FROM payments_transactions t
                LEFT JOIN payment_procedures p ON p.id_procedure = t.id_procedure AND p.id_notary = t.id_notary
                WHERE t.id_notary = :notaryId AND t.id_client = :clientId AND t.id_status = ' . self::STATUS_ACTIVE
            . $this->rangeClause('t.`date`', $from, $toExclusive, $params)
            . ' ORDER BY t.`date` ASC, t.id_transaction ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn (array $row): StatementTransaction => StatementTransaction::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Without a range a payment with no date is included (no date predicate);
     * with a range the NULL date falls out by itself. Undated payments go last.
     *
     * @return list<StatementPayment>
     */
    public function findPayments(int $notaryId, int $clientId, ?string $from, ?string $toExclusive): array
    {
        $params = ['notaryId' => $notaryId, 'clientId' => $clientId];

        $sql = 'SELECT id_payment, uuid, payment_date, comments, amount
                FROM payments_payments
                WHERE id_notary = :notaryId AND id_client = :clientId'
            . $this->rangeClause('payment_date', $from, $toExclusive, $params)
            . ' ORDER BY (payment_date IS NULL), payment_date ASC, id_payment ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn (array $row): StatementPayment => StatementPayment::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** Sum of active transactions strictly before $from. */
    public function sumActiveTransactionsBefore(int $notaryId, int $clientId, string $from): float
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(total), 0) FROM payments_transactions
             WHERE id_notary = :notaryId AND id_client = :clientId AND id_status = ' . self::STATUS_ACTIVE . '
               AND `date` < :from'
        );
        $stmt->execute(['notaryId' => $notaryId, 'clientId' => $clientId, 'from' => $from]);

        return (float) $stmt->fetchColumn();
    }

    /** Sum of payments strictly before $from. */
    public function sumPaymentsBefore(int $notaryId, int $clientId, string $from): float
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM payments_payments
             WHERE id_notary = :notaryId AND id_client = :clientId AND payment_date < :from'
        );
        $stmt->execute(['notaryId' => $notaryId, 'clientId' => $clientId, 'from' => $from]);

        return (float) $stmt->fetchColumn();
    }

    /**
     * $column is an internal constant, never user input. The dates always go as bound parameters.
     *
     * @param array<string, mixed> $params
     */
    private function rangeClause(string $column, ?string $from, ?string $toExclusive, array &$params): string
    {
        $sql = '';

        if ($from !== null) {
            $sql .= " AND $column >= :from";
            $params['from'] = $from;
        }

        if ($toExclusive !== null) {
            $sql .= " AND $column < :toExclusive";
            $params['toExclusive'] = $toExclusive;
        }

        return $sql;
    }
}
