<?php

declare(strict_types=1);

namespace App\Module\Auth\DTO;

use InvalidArgumentException;

class LoginRequest
{
    public function __construct(
        public readonly string $username,
        public readonly string $password,
    ) {
        if (empty($this->username)) {
            throw new InvalidArgumentException('Username is required');
        }
        if (empty($this->password)) {
            throw new InvalidArgumentException('Password is required');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            username: $data['username'] ?? '',
            password: $data['password'] ?? '',
        );
    }
}
