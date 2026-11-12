<?php

declare(strict_types=1);

namespace App\Module\Deed\Entity;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Read model of a single deed with its related records.
 */
final class Deed
{
    private const TIMEZONE = 'America/Mexico_City';

    /**
     * @param Participant[] $participants
     * @param Registration[] $registrations
     */
    public function __construct(
        public readonly int $id,
        public readonly int $notaryId,
        public readonly string $deedNumber,
        public readonly string $book,
        public readonly ?string $deedDate,
        public readonly int $actId,
        public readonly int $deedTypeId,
        public readonly ?int $subdivisionId,
        public readonly ?NamedReference $act,
        public readonly ?NamedReference $deedType,
        public readonly ?NamedReference $subdivision,
        public readonly ?string $property,
        public readonly ?string $deliveryDate,
        public readonly ?string $deliveredBy,
        public readonly ?string $observations,
        public readonly bool $isFinished,
        public readonly ?NamedReference $modifiedBy,
        public readonly ?DateTimeImmutable $modifiedAt,
        public readonly array $participants,
        public readonly array $registrations,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param Participant[] $participants
     * @param Registration[] $registrations
     */
    public static function fromRow(array $row, array $participants, array $registrations): self
    {
        $actId = (int) ($row['id_acto'] ?? 0);
        $deedTypeId = (int) ($row['id_tipo_escitura'] ?? 0);
        $subdivisionId = (int) ($row['id_fraccionamiento'] ?? 0);
        $userId = (int) ($row['id_usuario'] ?? 0);

        return new self(
            id: (int) $row['id_escritura'],
            notaryId: (int) $row['id_notaria'],
            deedNumber: trim((string) $row['num_escritura']),
            book: (string) ($row['libro'] ?? ''),
            deedDate: LegacyDate::toYmd($row['fecha_escritura'] ?? null),
            actId: $actId,
            deedTypeId: $deedTypeId,
            subdivisionId: $subdivisionId > 0 ? $subdivisionId : null,
            act: self::reference($actId, $row['nombre_acto'] ?? null),
            deedType: self::reference($deedTypeId, $row['nombre_tipo'] ?? null),
            // -1 and 0 both mean "no subdivision" in the legacy data
            subdivision: self::reference($subdivisionId, $row['nombre_fraccionamiento'] ?? null),
            property: $row['predio'] ?? null,
            deliveryDate: LegacyDate::toYmd($row['fecha_entrega'] ?? null),
            deliveredBy: $row['entrego'] ?? null,
            observations: $row['observaciones'] ?? null,
            isFinished: (int) ($row['terminada'] ?? 0) === 1,
            modifiedBy: self::reference($userId, $row['nombre_usuario'] ?? null),
            modifiedAt: self::parseModifiedAt($row['fecha_modificacion'] ?? null),
            participants: $participants,
            registrations: $registrations,
        );
    }

    private static function reference(int $id, mixed $name): ?NamedReference
    {
        if ($id <= 0) {
            return null;
        }

        $name = is_string($name) ? trim($name) : '';

        return new NamedReference($id, $name !== '' ? $name : null);
    }

    private static function parseModifiedAt(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return new DateTimeImmutable($value, new DateTimeZone(self::TIMEZONE));
    }
}
