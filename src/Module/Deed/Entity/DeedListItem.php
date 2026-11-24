<?php

declare(strict_types=1);

namespace App\Module\Deed\Entity;

use DateTimeImmutable;

/**
 * Read model for a row of the deeds list.
 */
final class DeedListItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $deedNumber,
        public readonly string $book,
        public readonly ?DateTimeImmutable $deedDate,
        public readonly ?string $actName,
        public readonly ?string $deedTypeName,
        public readonly bool $isFinished,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_escritura'],
            deedNumber: trim((string) $row['num_escritura']),
            book: (string) ($row['libro'] ?? ''),
            deedDate: self::parseDate($row['fecha_escritura'] ?? null),
            actName: $row['nombre_acto'] ?? null,
            deedTypeName: $row['nombre_tipo'] ?? null,
            isFinished: (int) ($row['terminada'] ?? 0) === 1,
        );
    }

    private static function parseDate(?string $value): ?DateTimeImmutable
    {
        $ymd = LegacyDate::toYmd($value);

        return $ymd !== null ? new DateTimeImmutable($ymd) : null;
    }
}
