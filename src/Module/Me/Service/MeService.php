<?php

declare(strict_types=1);

namespace App\Module\Me\Service;

use App\Module\Me\DTO\MeProfile;
use App\Module\Me\Exception\MeError;
use App\Module\Me\Exception\MeException;
use App\Module\Notary\Repository\NotaryRepository;
use App\Module\User\Entity\User;
use App\Module\User\Repository\UserRepository;

final class MeService
{
    private const NOTARY_ACTIVE = 1;

    public function __construct(
        private readonly UserRepository $users,
        private readonly NotaryRepository $notaries,
    ) {
    }

    /**
     * Both ids come from the token. The repository reads are not tenant-filtered (ADR-002), so the
     * user's notary is compared right after reading it and before the notary is read.
     *
     * @throws MeException
     */
    public function getProfile(int $userId, int $notaryId): MeProfile
    {
        $user = $this->users->findById($userId)
            ?? throw new MeException(MeError::UserNotFound);

        if ($user->notaryId !== $notaryId) {
            throw new MeException(MeError::TenantMismatch);
        }

        if ($user->status() !== User::STATUS_ACTIVE) {
            throw new MeException(MeError::UserInactive);
        }

        $notary = $this->notaries->findById($notaryId);
        if ($notary === null || $notary->status !== self::NOTARY_ACTIVE) {
            throw new MeException(MeError::NotaryInactive);
        }

        return new MeProfile($user, $notary);
    }
}
