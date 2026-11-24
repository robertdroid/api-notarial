<?php

declare(strict_types=1);

namespace App\Module\Deed\Entity;

final class Registration
{
    public function __construct(
        public readonly int $id,
        public readonly int $deedId,
        public readonly ?string $number,
        public readonly ?string $section,
        public readonly ?string $volume,
        public readonly ?string $date,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_inscripcion'],
            deedId: (int) $row['id_escritura'],
            number: $row['numero_inscripcion'] ?? null,
            section: $row['seccion_inscripcion'] ?? null,
            volume: $row['tomo_inscripcion'] ?? null,
            date: LegacyDate::toYmd($row['fecha_inscripcion'] ?? null),
        );
    }
}
