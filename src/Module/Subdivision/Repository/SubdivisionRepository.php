<?php

declare(strict_types=1);

namespace App\Module\Subdivision\Repository;

use App\Module\Subdivision\Entity\Subdivision;
use PDO;

class SubdivisionRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    public function findAllByNotary(int $notaryId, ?int $status, ?string $name): array
    {
        $sql = "SELECT * FROM fraccionamientos WHERE id_notaria = :notaryId";
        $params = ['notaryId' => $notaryId];

        if ($status !== null) {
            $sql .= " AND id_estatus = :status";
            $params['status'] = $status;
        }

        if ($name !== null && $name !== '') {
            $sql .= " AND LOWER(nombre_fraccionamiento) LIKE LOWER(:name)";
            $params['name'] = '%' . $name . '%';
        }

        $sql .= " ORDER BY nombre_fraccionamiento ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $subdivisions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $subdivisions[] = Subdivision::fromRow($row);
        }

        return $subdivisions;
    }

    public function findByIdAndNotary(int $id, int $notaryId): ?Subdivision
    {
        $sql = "SELECT * FROM fraccionamientos WHERE id_fraccionamiento = :id AND id_notaria = :notaryId";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id, 'notaryId' => $notaryId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Subdivision::fromRow($row) : null;
    }

    public function existsByNameAndNotary(string $name, int $notaryId, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM fraccionamientos WHERE LOWER(nombre_fraccionamiento) = LOWER(:name) AND id_notaria = :notaryId";
        $params = ['name' => $name, 'notaryId' => $notaryId];

        if ($excludeId !== null) {
            $sql .= " AND id_fraccionamiento != :excludeId";
            $params['excludeId'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(Subdivision $subdivision): int
    {
        $sql = "INSERT INTO fraccionamientos (id_notaria, nombre_fraccionamiento, id_usuario, fecha_modificacion, id_estatus)
                VALUES (:notaryId, :name, :userId, :modDate, :status)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'notaryId' => $subdivision->notaryId,
            'name' => $subdivision->name,
            'userId' => $subdivision->userId,
            'modDate' => $subdivision->modificationDate->format('Y-m-d H:i:s'),
            'status' => $subdivision->statusId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(Subdivision $subdivision): void
    {
        $sql = "UPDATE fraccionamientos
                SET nombre_fraccionamiento = :name,
                    id_usuario = :userId,
                    fecha_modificacion = :modDate,
                    id_estatus = :status
                WHERE id_fraccionamiento = :id AND id_notaria = :notaryId";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $subdivision->id,
            'notaryId' => $subdivision->notaryId,
            'name' => $subdivision->name,
            'userId' => $subdivision->userId,
            'modDate' => $subdivision->modificationDate->format('Y-m-d H:i:s'),
            'status' => $subdivision->statusId,
        ]);
    }
}
