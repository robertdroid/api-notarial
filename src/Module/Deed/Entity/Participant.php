<?php

declare(strict_types=1);

namespace App\Module\Deed\Entity;

final class Participant
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_INACTIVE = 2;

    public function __construct(
        public readonly int $id,
        public readonly int $deedId,
        public readonly string $name,
        public readonly int $typeId,
        public readonly int $statusId,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_participante'],
            deedId: (int) $row['id_escritura'],
            name: (string) ($row['nombre_participante'] ?? ''),
            typeId: (int) $row['id_tipo_participante'],
            statusId: (int) ($row['id_estatus'] ?? 0),
        );
    }

    /** Only 1 is active: the legacy system also wrote 0 for "deleted". */
    public function isActive(): bool
    {
        return $this->statusId === self::STATUS_ACTIVE;
    }

    public function type(): ?ParticipantType
    {
        return ParticipantType::tryFrom($this->typeId);
    }
}
