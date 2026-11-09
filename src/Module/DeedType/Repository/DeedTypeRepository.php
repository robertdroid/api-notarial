<?php

declare(strict_types=1);

namespace App\Module\DeedType\Repository;

use App\Module\DeedType\Entity\DeedType;
use PDO;

class DeedTypeRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    public function findAllByNotary(int $notaryId, ?int $status, ?string $name): array
    {
        $sql = "SELECT * FROM tipo_escritura WHERE id_notaria = :notaryId";
        $params = ['notaryId' => $notaryId];

        if ($status !== null) {
            $sql .= " AND id_estatus = :status";
            $params['status'] = $status;
        }

        if ($name !== null && $name !== '') {
            $sql .= " AND LOWER(nombre_tipo) LIKE LOWER(:name)";
            $params['name'] = '%' . $name . '%';
        }

        $sql .= " ORDER BY nombre_tipo ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $deedTypes = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $deedTypes[] = DeedType::fromRow($row);
        }

        return $deedTypes;
    }

    public function findByIdAndNotary(int $id, int $notaryId): ?DeedType
    {
        $sql = "SELECT * FROM tipo_escritura WHERE id_tipo_escitura = :id AND id_notaria = :notaryId";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id, 'notaryId' => $notaryId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? DeedType::fromRow($row) : null;
    }

    public function existsByNameAndNotary(string $name, int $notaryId, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM tipo_escritura WHERE LOWER(nombre_tipo) = LOWER(:name) AND id_notaria = :notaryId";
        $params = ['name' => $name, 'notaryId' => $notaryId];

        if ($excludeId !== null) {
            $sql .= " AND id_tipo_escitura != :excludeId";
            $params['excludeId'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(DeedType $deedType): int
    {
        $sql = "INSERT INTO tipo_escritura (
                    id_notaria, nombre_tipo, descripcion_tipo,
                    max_inscripciones, activar_fraccionamientos,
                    id_usuario, fecha_modificacion, id_estatus
                ) VALUES (
                    :notaryId, :name, :description,
                    :maxInscriptions, :enableSubdivisions,
                    :userId, :modDate, :status
                )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'notaryId' => $deedType->notaryId,
            'name' => $deedType->name,
            'description' => $deedType->description,
            'maxInscriptions' => $deedType->maxInscriptions,
            'enableSubdivisions' => $deedType->enableSubdivisions,
            'userId' => $deedType->userId,
            'modDate' => $deedType->modificationDate->format('Y-m-d H:i:s'),
            'status' => $deedType->statusId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(DeedType $deedType): void
    {
        $sql = "UPDATE tipo_escritura SET
                    nombre_tipo = :name,
                    descripcion_tipo = :description,
                    max_inscripciones = :maxInscriptions,
                    activar_fraccionamientos = :enableSubdivisions,
                    id_usuario = :userId,
                    fecha_modificacion = :modDate,
                    id_estatus = :status
                WHERE id_tipo_escitura = :id AND id_notaria = :notaryId";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $deedType->id,
            'notaryId' => $deedType->notaryId,
            'name' => $deedType->name,
            'description' => $deedType->description,
            'maxInscriptions' => $deedType->maxInscriptions,
            'enableSubdivisions' => $deedType->enableSubdivisions,
            'userId' => $deedType->userId,
            'modDate' => $deedType->modificationDate->format('Y-m-d H:i:s'),
            'status' => $deedType->statusId,
        ]);
    }
}
