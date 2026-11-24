<?php

declare(strict_types=1);

namespace App\Module\Auth\DTO;

class AuthUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly ?string $passwordHash,
        public readonly ?string $passwordMd5,
        public readonly int $notaryId,
        public readonly int $userStatus,
        public readonly int $notaryStatus,
        public readonly ?string $userAccess,
    ) {}

    public function isActive(): bool
    {
        return $this->userStatus === 1 && $this->notaryStatus === 1;
    }

    public function getFeatures(): array
    {
        if (empty($this->userAccess)) {
            return [];
        }

        return array_map(
            'trim',
            explode(',', $this->userAccess)
        );
    }

    public function hasPasswordHash(): bool
    {
        return !empty($this->passwordHash);
    }
}
