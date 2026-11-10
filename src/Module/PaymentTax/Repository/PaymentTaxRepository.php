<?php

declare(strict_types=1);

namespace App\Module\PaymentTax\Repository;

use App\Module\PaymentTax\Entity\PaymentTax;
use PDO;

class PaymentTaxRepository
{
    private const COLUMNS = 'id_tax, id_notary, tax_name, id_status';

    // Column is utf8mb3_bin; convert to get case- and accent-insensitive comparison.
    private const NAME_EXPR = 'CONVERT(tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci';

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * @return PaymentTax[]
     */
    public function findAllByNotary(int $notaryId, ?int $status, ?string $name): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM payment_taxes WHERE id_notary = :notaryId';
        $params = ['notaryId' => $notaryId];

        if ($status !== null) {
            $sql .= ' AND id_status = :status';
            $params['status'] = $status;
        }

        if ($name !== null && $name !== '') {
            $sql .= ' AND ' . self::NAME_EXPR . " LIKE :name ESCAPE '!'";
            $params['name'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $name) . '%';
        }

        $sql .= ' ORDER BY tax_name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $taxes = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $taxes[] = PaymentTax::fromRow($row);
        }

        return $taxes;
    }

    public function findByIdAndNotary(int $id, int $notaryId): ?PaymentTax
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM payment_taxes WHERE id_tax = :id AND id_notary = :notaryId';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id, 'notaryId' => $notaryId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? PaymentTax::fromRow($row) : null;
    }

    public function existsByNameAndNotary(string $name, int $notaryId, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM payment_taxes WHERE id_notary = :notaryId AND '
            . self::NAME_EXPR . ' = :name';
        $params = ['notaryId' => $notaryId, 'name' => $name];

        if ($excludeId !== null) {
            $sql .= ' AND id_tax <> :excludeId';
            $params['excludeId'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(PaymentTax $tax): int
    {
        $sql = 'INSERT INTO payment_taxes (id_notary, tax_name, id_status) VALUES (:notaryId, :name, :status)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'notaryId' => $tax->notaryId,
            'name' => $tax->name,
            'status' => $tax->statusId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(PaymentTax $tax): void
    {
        $sql = 'UPDATE payment_taxes SET tax_name = :name, id_status = :status
                WHERE id_tax = :id AND id_notary = :notaryId';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $tax->id,
            'notaryId' => $tax->notaryId,
            'name' => $tax->name,
            'status' => $tax->statusId,
        ]);
    }
}
