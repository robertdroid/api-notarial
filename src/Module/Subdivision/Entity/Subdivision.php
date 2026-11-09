<?php

declare(strict_types=1);

namespace App\Module\Subdivision\Entity;

use DateTime;

final class Subdivision
{
    public function __construct(
        public readonly int $id,
        public readonly int $notaryId,
        public readonly string $name,
        public readonly int $userId,
        public readonly DateTime $modificationDate,
        public readonly int $statusId,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_fraccionamiento'],
            notaryId: (int) $row['id_notaria'],
            name: $row['nombre_fraccionamiento'],
            userId: (int) $row['id_usuario'],
            modificationDate: new DateTime($row['fecha_modificacion']),
            statusId: (int) $row['id_estatus'],
        );
    }
}
