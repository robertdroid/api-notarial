<?php

declare(strict_types=1);

namespace App\Module\PaymentClient\Repository;

use App\Module\PaymentClient\Entity\PaymentClient;
use PDO;

class PaymentClientRepository
{
    private const COLUMNS = 'id_client, id_notary, full_name, phone, email, id_status';

    // Column is utf8mb3_bin; convert to get case- and accent-insensitive comparison/sort.
    private const NAME_EXPR = 'CONVERT(full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci';

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * @return PaymentClient[]
     */
    public function findAllByNotary(int $notaryId, ?int $status, ?string $name): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM payments_clients WHERE id_notary = :notaryId';
        $params = ['notaryId' => $notaryId];

        if ($status !== null) {
            $sql .= ' AND id_status = :status';
            $params['status'] = $status;
        }

        if ($name !== null && $name !== '') {
            $sql .= ' AND ' . self::NAME_EXPR . " LIKE :name ESCAPE '!'";
            $params['name'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $name) . '%';
        }

        $sql .= ' ORDER BY ' . self::NAME_EXPR . ' ASC, id_client ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $clients = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $clients[] = PaymentClient::fromRow($row);
        }

        return $clients;
    }

    public function findByIdAndNotary(int $id, int $notaryId): ?PaymentClient
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM payments_clients WHERE id_client = :id AND id_notary = :notaryId';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id, 'notaryId' => $notaryId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? PaymentClient::fromRow($row) : null;
    }

    public function create(PaymentClient $client): int
    {
        $sql = 'INSERT INTO payments_clients (id_notary, full_name, phone, email, id_status)
                VALUES (:notaryId, :fullName, :phone, :email, :status)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'notaryId' => $client->notaryId,
            'fullName' => $client->fullName,
            'phone' => $client->phone,
            'email' => $client->email,
            'status' => $client->statusId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(PaymentClient $client): void
    {
        $sql = 'UPDATE payments_clients
                SET full_name = :fullName, phone = :phone, email = :email, id_status = :status
                WHERE id_client = :id AND id_notary = :notaryId';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $client->id,
            'notaryId' => $client->notaryId,
            'fullName' => $client->fullName,
            'phone' => $client->phone,
            'email' => $client->email,
            'status' => $client->statusId,
        ]);
    }
}
