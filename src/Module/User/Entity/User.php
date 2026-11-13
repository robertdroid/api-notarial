<?php

declare(strict_types=1);

namespace App\Module\User\Entity;

use DateTimeImmutable;

/**
 * Notary user. Intentionally has no password properties: this module never reads them.
 */
final class User
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_INACTIVE = 2;

    /**
     * @param string[] $access
     * @param int $statusId raw id_estatus (legacy data may hold 0)
     */
    public function __construct(
        public readonly int $id,
        public readonly int $notaryId,
        public readonly string $username,
        public readonly string $firstName,
        public readonly string $paternalLastName,
        public readonly string $maternalLastName,
        public readonly array $access,
        public readonly int $statusId,
        public readonly ?DateTimeImmutable $lastAccess,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_usuario'],
            notaryId: (int) $row['id_notaria'],
            username: (string) $row['usuario'],
            firstName: (string) $row['nombre'],
            paternalLastName: (string) $row['apellido_paterno'],
            maternalLastName: (string) ($row['apellido_materno'] ?? ''),
            access: AccessCode::fromCsv($row['user_access'] ?? null),
            statusId: (int) ($row['id_estatus'] ?? 0),
            lastAccess: isset($row['ultimo_acceso']) ? new DateTimeImmutable((string) $row['ultimo_acceso']) : null,
        );
    }

    /**
     * Normalized status exposed by the API: anything other than active (0, NULL, 2...) is inactive.
     */
    public function status(): int
    {
        return $this->statusId === self::STATUS_ACTIVE ? self::STATUS_ACTIVE : self::STATUS_INACTIVE;
    }

    /**
     * First name and last names joined by single spaces; blank parts (e.g. no maternal last name) are skipped.
     */
    public function fullName(): string
    {
        $parts = array_filter(
            array_map('trim', [$this->firstName, $this->paternalLastName, $this->maternalLastName]),
            static fn (string $part): bool => $part !== '',
        );

        return implode(' ', $parts);
    }
}
