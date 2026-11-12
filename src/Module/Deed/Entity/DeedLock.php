<?php

declare(strict_types=1);

namespace App\Module\Deed\Entity;

/**
 * Result of SELECT ... FOR UPDATE on a deed: what update, finish and sub-resources need.
 */
final class DeedLock
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $actId,
        public readonly ?int $deedTypeId,
        public readonly ?int $subdivisionId,
        public readonly bool $isFinished,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_escritura'],
            actId: self::positiveOrNull($row['id_acto'] ?? null),
            deedTypeId: self::positiveOrNull($row['id_tipo_escitura'] ?? null),
            subdivisionId: self::positiveOrNull($row['id_fraccionamiento'] ?? null),
            isFinished: (int) ($row['terminada'] ?? 0) === 1,
        );
    }

    /** Legacy "none" markers (NULL, 0, -1) become null. */
    private static function positiveOrNull(mixed $value): ?int
    {
        $int = $value === null ? 0 : (int) $value;

        return $int > 0 ? $int : null;
    }
}
