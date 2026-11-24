<?php

declare(strict_types=1);

namespace App\Module\Me\DTO;

use App\Module\Notary\Entity\Notary;
use App\Module\User\Entity\User;

final readonly class MeProfile
{
    public function __construct(
        public User $user,
        public Notary $notary,
    ) {
    }
}
