<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\Repository;

use App\Module\PaymentTransaction\DTO\CreateTransactionRequest;
use App\Module\PaymentTransaction\DTO\TransactionDetailItem;
use App\Module\PaymentTransaction\DTO\UpdateTransactionRequest;
use App\Module\PaymentTransaction\Entity\PaymentTransaction;
use App\Module\PaymentTransaction\Entity\PaymentTransactionDetail;
use App\Module\PaymentTransaction\Exception\DuplicateTransactionUuidException;
use PDO;

/**
 * Data access only. Never opens transactions: the Service owns the atomic boundary.
 */
class PaymentTransactionRepository
{
    private const MYSQL_DUP_ENTRY = 1062;

    private const HEADER_SQL = 'SELECT t.id_transaction, t.uuid, t.id_notary, t.id_client, t.id_procedure,
                   t.reference, t.`date`, t.responsible, t.fee, t.taxes, t.total, t.comments, t.id_status,
                   c.full_name AS client_name, p.procedure_name
            FROM payments_transactions t
            LEFT JOIN payments_clients c   ON c.id_client = t.id_client       AND c.id_notary = t.id_notary
            LEFT JOIN payment_procedures p ON p.id_procedure = t.id_procedure AND p.id_notary = t.id_notary';

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * With $forUpdate the header row is locked first (to be used inside a transaction).
     */
    public function findByIdAndNotary(int $id, int $notaryId, bool $forUpdate = false): ?PaymentTransaction
    {
        if ($forUpdate) {
            $lock = $this->db->prepare(
                'SELECT id_transaction FROM payments_transactions
                 WHERE id_transaction = :id AND id_notary = :notaryId
                 FOR UPDATE'
            );
            $lock->execute(['id' => $id, 'notaryId' => $notaryId]);

            if ($lock->fetch(PDO::FETCH_ASSOC) === false) {
                return null;
            }
        }

        return $this->fetchOne('t.id_transaction = :id AND t.id_notary = :notaryId', [
            'id' => $id,
            'notaryId' => $notaryId,
        ]);
    }

    public function findByUuidAndNotary(string $uuid, int $notaryId): ?PaymentTransaction
    {
        return $this->fetchOne('t.uuid = :uuid AND t.id_notary = :notaryId', [
            'uuid' => $uuid,
            'notaryId' => $notaryId,
        ]);
    }

    /**
     * @throws DuplicateTransactionUuidException when (id_notary, uuid) already exists
     */
    public function insert(CreateTransactionRequest $dto, int $notaryId, float $taxes, float $total): int
    {
        $sql = 'INSERT INTO payments_transactions
                    (uuid, id_notary, id_client, id_procedure, reference, `date`, responsible, fee, taxes, total, comments, id_status)
                VALUES
                    (:uuid, :notaryId, :clientId, :procedureId, :reference, :date, :responsible, :fee, :taxes, :total, :comments, '
            . PaymentTransaction::STATUS_ACTIVE . ')';

        try {
            $this->db->prepare($sql)->execute([
                'uuid' => $dto->uuid,
                'notaryId' => $notaryId,
                'clientId' => $dto->clientId,
                'procedureId' => $dto->procedureId,
                'reference' => $dto->reference,
                'date' => $dto->date . ' 00:00:00',
                'responsible' => $dto->responsible,
                'fee' => $dto->fee,
                'taxes' => $taxes,
                'total' => $total,
                'comments' => $dto->comments,
            ]);
        } catch (\PDOException $e) {
            // errorInfo[1] is the driver code; getCode() is the SQLSTATE 23000 shared by other integrity errors
            if (($e->errorInfo[1] ?? null) === self::MYSQL_DUP_ENTRY) {
                throw new DuplicateTransactionUuidException($e);
            }

            throw $e;
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Does not touch uuid, id_client, id_notary or id_status.
     * No rowCount() check: it is 0 when the values do not change.
     */
    public function update(int $id, int $notaryId, UpdateTransactionRequest $dto, float $taxes, float $total): void
    {
        $sql = 'UPDATE payments_transactions
                SET id_procedure = :procedureId, `date` = :date, fee = :fee, taxes = :taxes, total = :total,
                    reference = :reference, responsible = :responsible, comments = :comments
                WHERE id_transaction = :id AND id_notary = :notaryId';

        $this->db->prepare($sql)->execute([
            'procedureId' => $dto->procedureId,
            'date' => $dto->date . ' 00:00:00',
            'fee' => $dto->fee,
            'taxes' => $taxes,
            'total' => $total,
            'reference' => $dto->reference,
            'responsible' => $dto->responsible,
            'comments' => $dto->comments,
            'id' => $id,
            'notaryId' => $notaryId,
        ]);
    }

    public function deleteDetails(int $transactionId): void
    {
        $this->db->prepare('DELETE FROM payments_transaction_details WHERE id_transaction = :transactionId')
            ->execute(['transactionId' => $transactionId]);
    }

    /**
     * Single multi-row INSERT; an empty list runs no SQL.
     *
     * @param TransactionDetailItem[] $items
     */
    public function insertDetails(int $transactionId, array $items): void
    {
        if ($items === []) {
            return;
        }

        // Native prepares do not allow repeating a named placeholder, hence the indexed names.
        $params = [];
        $rows = [];
        foreach (array_values($items) as $i => $item) {
            $rows[] = "(:tx$i, :tax$i, :qty$i, :amount$i, :date$i, :comments$i)";
            $params["tx$i"] = $transactionId;
            $params["tax$i"] = $item->taxId;
            $params["qty$i"] = $item->quantity;
            $params["amount$i"] = $item->amount;
            $params["date$i"] = $item->date !== null ? $item->date . ' 00:00:00' : null;
            $params["comments$i"] = $item->comments;
        }

        $sql = 'INSERT INTO payments_transaction_details (id_transaction, id_tax, quantity, amount, `date`, comments) VALUES '
            . implode(', ', $rows);

        $this->db->prepare($sql)->execute($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function fetchOne(string $where, array $params): ?PaymentTransaction
    {
        $stmt = $this->db->prepare(self::HEADER_SQL . ' WHERE ' . $where);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        // Details are read only after the header proved to belong to the notary
        return PaymentTransaction::fromRow($row, $this->findDetails((int) $row['id_transaction']));
    }

    /**
     * @return PaymentTransactionDetail[]
     */
    private function findDetails(int $transactionId): array
    {
        $sql = 'SELECT d.id_tax, x.tax_name, d.quantity, d.amount, d.`date`, d.comments
                FROM payments_transaction_details d
                LEFT JOIN payment_taxes x ON x.id_tax = d.id_tax
                WHERE d.id_transaction = :transactionId
                ORDER BY CONVERT(x.tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci ASC, d.id_tax ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['transactionId' => $transactionId]);

        $details = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $details[] = PaymentTransactionDetail::fromRow($row);
        }

        return $details;
    }
}
