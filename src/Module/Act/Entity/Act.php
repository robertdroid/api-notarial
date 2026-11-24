<?php

declare(strict_types=1);

namespace App\Module\Act\Entity;

use DateTime;

final class Act
{
    public function __construct(
        public readonly int $id,
        public readonly int $notaryId,
        public readonly string $name,
        public readonly string $description,
        public readonly int $userId,
        public readonly DateTime $modificationDate,
        public readonly int $statusId,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_acto'],
            notaryId: (int) $row['id_notaria'],
            name: $row['nombre_acto'],
            description: $row['descripcion_acto'] ?? '',
            userId: (int) $row['id_usuario'],
            modificationDate: new DateTime($row['fecha_modificacion']),
            statusId: (int) $row['id_estatus'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'notaryId' => $this->notaryId,
            'name' => $this->name,
            'description' => $this->description,
            'userId' => $this->userId,
            'modificationDate' => $this->modificationDate->format('Y-m-d H:i:s'),
            'statusId' => $this->statusId,
        ];
    }
}
