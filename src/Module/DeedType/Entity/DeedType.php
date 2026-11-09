<?php

declare(strict_types=1);

namespace App\Module\DeedType\Entity;

use DateTime;

final class DeedType
{
    public function __construct(
        public readonly int $id,
        public readonly int $notaryId,
        public readonly string $name,
        public readonly string $description,
        public readonly int $maxInscriptions,
        public readonly int $enableSubdivisions,
        public readonly int $userId,
        public readonly DateTime $modificationDate,
        public readonly int $statusId,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_tipo_escitura'], // Legacy typo in DB
            notaryId: (int) $row['id_notaria'],
            name: $row['nombre_tipo'],
            description: $row['descripcion_tipo'],
            maxInscriptions: (int) $row['max_inscripciones'],
            enableSubdivisions: (int) $row['activar_fraccionamientos'],
            userId: (int) $row['id_usuario'],
            modificationDate: new DateTime($row['fecha_modificacion']),
            statusId: (int) $row['id_estatus'],
        );
    }
}
