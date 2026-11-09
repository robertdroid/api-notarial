<?php

declare(strict_types=1);

namespace App\Module\Act\Repository;

use App\Module\Act\Entity\Act;
use PDO;

class ActRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    public function findAllByNotary(int $notaryId, ?int $status, ?string $name): array
    {
        $sql = "SELECT * FROM actos_notaria WHERE id_notaria = :notaryId";
        $params = ['notaryId' => $notaryId];

        if ($status !== null) {
            $sql .= " AND id_status = :status";
            $params['status'] = $status;
        }

        if ($name !== null && $name !== '') {
            $sql .= " AND LOWER(nombre_acto) LIKE LOWER(:name)";
            $params['name'] = '%' . $name . '%';
        }

        $sql .= " ORDER BY nombre_acto ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $acts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $acts[] = Act::fromRow($row);
        }

        return $acts;
    }

    public function findByIdAndNotary(int $id, int $notaryId): ?Act
    {
        $sql = "SELECT * FROM actos_notaria WHERE id_acto = :id AND id_notaria = :notaryId";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id, 'notaryId' => $notaryId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Act::fromRow($row) : null;
    }

    public function existsByNameAndNotary(string $name, int $notaryId, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM actos_notaria WHERE LOWER(nombre_acto) = LOWER(:name) AND id_notaria = :notaryId";
        $params = ['name' => $name, 'notaryId' => $notaryId];

        if ($excludeId !== null) {
            $sql .= " AND id_acto != :excludeId";
            $params['excludeId'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(Act $act): int
    {
        $sql = "INSERT INTO actos_notaria (id_notaria, nombre_acto, descripcion_acto, id_usuario, fecha_modificacion, id_status)
                VALUES (:notaryId, :name, :description, :userId, :modDate, :status)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'notaryId' => $act->notaryId,
            'name' => $act->name,
            'description' => $act->description,
            'userId' => $act->userId,
            'modDate' => $act->modificationDate->format('Y-m-d H:i:s'),
            'status' => $act->statusId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(Act $act): void
    {
        $sql = "UPDATE actos_notaria
                SET nombre_acto = :name,
                    descripcion_acto = :description,
                    id_usuario = :userId,
                    fecha_modificacion = :modDate,
                    id_status = :status
                WHERE id_acto = :id AND id_notaria = :notaryId";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $act->id,
            'notaryId' => $act->notaryId,
            'name' => $act->name,
            'description' => $act->description,
            'userId' => $act->userId,
            'modDate' => $act->modificationDate->format('Y-m-d H:i:s'),
            'status' => $act->statusId,
        ]);
    }
}
