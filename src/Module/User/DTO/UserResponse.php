<?php

declare(strict_types=1);

namespace App\Module\User\DTO;

use App\Module\User\Entity\User;

final class UserResponse
{
    /**
     * @return array<string, mixed>
     */
    public static function fromEntity(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'firstName' => $user->firstName,
            'paternalLastName' => $user->paternalLastName,
            'maternalLastName' => $user->maternalLastName,
            'access' => $user->access,
            'status' => $user->status(),
            'lastAccess' => $user->lastAccess?->format('Y-m-d\TH:i:s'),
        ];
    }

    /**
     * @param User[] $users
     * @return array<int, array<string, mixed>>
     */
    public static function fromCollection(array $users): array
    {
        return array_map(static fn (User $user): array => self::fromEntity($user), $users);
    }
}
