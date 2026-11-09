<?php

declare(strict_types=1);

namespace App\Module\Auth\DTO;

use InvalidArgumentException;

class LoginRequest
{
    public function __construct(
        public readonly string $usuario,
        public readonly string $contrasenia,
    ) {
        if (empty($this->usuario)) {
            throw new InvalidArgumentException('Usuario es requerido');
        }
        if (empty($this->contrasenia)) {
            throw new InvalidArgumentException('Contraseña es requerida');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            usuario: $data['usuario'] ?? '',
            contrasenia: $data['contrasenia'] ?? '',
        );
    }
}
