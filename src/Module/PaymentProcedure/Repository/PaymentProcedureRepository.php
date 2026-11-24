<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedure\Repository;

use App\Module\PaymentProcedure\Entity\PaymentProcedure;
use PDO;

class PaymentProcedureRepository
{
    private const COLUMNS = 'id_procedure, id_notary, procedure_name, id_status';

    // Column is utf8mb3_bin; convert to get case- and accent-insensitive comparison.
    private const NAME_EXPR = 'CONVERT(procedure_name USING utf8mb4) COLLATE utf8mb4_unicode_ci';

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * @return PaymentProcedure[]
     */
    public function findAllByNotary(int $notaryId, ?int $status, ?string $name): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM payment_procedures WHERE id_notary = :notaryId';
        $params = ['notaryId' => $notaryId];

        if ($status !== null) {
            $sql .= ' AND id_status = :status';
            $params['status'] = $status;
        }

        if ($name !== null && $name !== '') {
            $sql .= ' AND ' . self::NAME_EXPR . " LIKE :name ESCAPE '!'";
            $params['name'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $name) . '%';
        }

        $sql .= ' ORDER BY procedure_name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $procedures = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $procedures[] = PaymentProcedure::fromRow($row);
        }

        return $procedures;
    }

    public function findByIdAndNotary(int $id, int $notaryId): ?PaymentProcedure
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM payment_procedures WHERE id_procedure = :id AND id_notary = :notaryId';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id, 'notaryId' => $notaryId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? PaymentProcedure::fromRow($row) : null;
    }

    public function existsByNameAndNotary(string $name, int $notaryId, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM payment_procedures WHERE id_notary = :notaryId AND '
            . self::NAME_EXPR . ' = :name';
        $params = ['notaryId' => $notaryId, 'name' => $name];

        if ($excludeId !== null) {
            $sql .= ' AND id_procedure <> :excludeId';
            $params['excludeId'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(PaymentProcedure $procedure): int
    {
        $sql = 'INSERT INTO payment_procedures (id_notary, procedure_name, id_status) VALUES (:notaryId, :name, :status)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'notaryId' => $procedure->notaryId,
            'name' => $procedure->name,
            'status' => $procedure->statusId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(PaymentProcedure $procedure): void
    {
        $sql = 'UPDATE payment_procedures SET procedure_name = :name, id_status = :status
                WHERE id_procedure = :id AND id_notary = :notaryId';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $procedure->id,
            'notaryId' => $procedure->notaryId,
            'name' => $procedure->name,
            'status' => $procedure->statusId,
        ]);
    }
}
