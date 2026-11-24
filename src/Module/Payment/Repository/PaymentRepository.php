<?php

declare(strict_types=1);

namespace App\Module\Payment\Repository;

use App\Module\Payment\DTO\CreatePaymentRequest;
use App\Module\Payment\Entity\Payment;
use App\Module\Payment\Exception\DuplicatePaymentUuidException;
use PDO;

/**
 * Data access only. Never opens transactions: every write is a single statement.
 */
class PaymentRepository
{
    private const MYSQL_DUP_ENTRY = 1062;

    private const SELECT_SQL = 'SELECT p.id_payment, p.uuid, p.id_notary, p.id_client, p.amount,
                   p.payment_date, p.comments, c.full_name AS client_name
            FROM payments_payments p
            LEFT JOIN payments_clients c ON c.id_client = p.id_client AND c.id_notary = p.id_notary';

    public function __construct(
        private readonly PDO $db
    ) {
    }

    public function findByIdAndNotary(int $id, int $notaryId): ?Payment
    {
        return $this->fetchOne('p.id_payment = :id AND p.id_notary = :notaryId', [
            'id' => $id,
            'notaryId' => $notaryId,
        ]);
    }

    public function findByUuidAndNotary(string $uuid, int $notaryId): ?Payment
    {
        // The column is compared as is: no function on it, so the unique index is used and utf8mb3_bin is respected
        return $this->fetchOne('p.uuid = :uuid AND p.id_notary = :notaryId', [
            'uuid' => $uuid,
            'notaryId' => $notaryId,
        ]);
    }

    /**
     * @throws DuplicatePaymentUuidException when (id_notary, uuid) already exists
     */
    public function insert(CreatePaymentRequest $dto, int $notaryId): int
    {
        $sql = 'INSERT INTO payments_payments (uuid, id_notary, id_client, amount, payment_date, comments)
                VALUES (:uuid, :notaryId, :clientId, :amount, :paymentDate, :comments)';

        try {
            $this->db->prepare($sql)->execute([
                'uuid' => $dto->uuid,
                'notaryId' => $notaryId,
                'clientId' => $dto->clientId,
                'amount' => $dto->amount,
                'paymentDate' => $dto->paymentDate . ' 00:00:00',
                'comments' => $dto->comments,
            ]);
        } catch (\PDOException $e) {
            // errorInfo[1] is the driver code; getCode() is the SQLSTATE 23000 shared by other integrity errors
            if (($e->errorInfo[1] ?? null) === self::MYSQL_DUP_ENTRY) {
                throw new DuplicatePaymentUuidException($e);
            }

            throw $e;
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Does not touch uuid, id_client or id_notary.
     * No rowCount() check: it is 0 when the values do not change.
     * A null $paymentDate (legacy row without date) is written as NULL.
     */
    public function update(int $id, int $notaryId, float $amount, ?string $paymentDate, string $comments): void
    {
        $sql = 'UPDATE payments_payments
                SET amount = :amount, payment_date = :paymentDate, comments = :comments
                WHERE id_payment = :id AND id_notary = :notaryId';

        $this->db->prepare($sql)->execute([
            'amount' => $amount,
            'paymentDate' => $paymentDate !== null ? $paymentDate . ' 00:00:00' : null,
            'comments' => $comments,
            'id' => $id,
            'notaryId' => $notaryId,
        ]);
    }

    /**
     * Physical delete. Unlike update(), rowCount() is reliable here:
     * 0 rows means the payment does not exist or belongs to another notary.
     */
    public function delete(int $id, int $notaryId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM payments_payments WHERE id_payment = :id AND id_notary = :notaryId'
        );
        $stmt->execute(['id' => $id, 'notaryId' => $notaryId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function fetchOne(string $where, array $params): ?Payment
    {
        $stmt = $this->db->prepare(self::SELECT_SQL . ' WHERE ' . $where);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Payment::fromRow($row);
    }
}
