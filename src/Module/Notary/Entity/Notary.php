<?php

declare(strict_types=1);

namespace App\Module\Notary\Entity;

final class Notary
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $notaryName,
        public readonly string $city,
        public readonly string $address,
        public readonly string $phone,
        public readonly string $rfc,
        public readonly string $logo,
        public readonly int $status,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_notaria'],
            name: $row['nombre_notaria'],
            notaryName: $row['notario_titular'],
            city: $row['ciudad'],
            address: $row['direccion'],
            phone: $row['telefono'],
            rfc: $row['rfc'],
            logo: $row['logo'],
            status: (int) $row['id_estatus'],
        );
    }

    public function hasLogo(): bool
    {
        return $this->logo !== '';
    }
}
