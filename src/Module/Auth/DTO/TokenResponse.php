<?php

declare(strict_types=1);

namespace App\Module\Auth\DTO;

class TokenResponse
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $expiresIn,
    ) {}

    public function toArray(): array
    {
        return [
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'expiresIn' => $this->expiresIn,
            'tokenType' => 'Bearer',
        ];
    }
}
