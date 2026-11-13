<?php

declare(strict_types=1);

namespace App\Module\Me\DTO;

use App\Module\Notary\DTO\NotaryResponse;
use App\Module\User\DTO\UserResponse;

final class MeResponse
{
    /**
     * @return array{user: array<string, mixed>, notary: array<string, mixed>}
     */
    public static function fromProfile(MeProfile $profile): array
    {
        $user = UserResponse::fromEntity($profile->user);

        // fullName goes right after maternalLastName
        $position = (int) array_search('maternalLastName', array_keys($user), true) + 1;
        $user = array_slice($user, 0, $position, true)
            + ['fullName' => $profile->user->fullName()]
            + array_slice($user, $position, null, true);

        return [
            'user' => $user,
            'notary' => NotaryResponse::fromEntity($profile->notary),
        ];
    }
}
