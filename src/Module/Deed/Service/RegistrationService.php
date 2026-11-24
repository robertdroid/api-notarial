<?php

declare(strict_types=1);

namespace App\Module\Deed\Service;

use App\Core\Database\TransactionManager;
use App\Module\Deed\DTO\RegistrationInput;
use App\Module\Deed\Entity\DeedLock;
use App\Module\Deed\Entity\Registration;
use App\Module\Deed\Exception\DeedError;
use App\Module\Deed\Exception\DeedException;
use App\Module\Deed\Repository\DeedRepository;
use App\Module\Deed\Repository\RegistrationRepository;
use DateTimeImmutable;

class RegistrationService
{
    public function __construct(
        private readonly DeedRepository $deeds,
        private readonly RegistrationRepository $registrations,
        private readonly DeedReferenceValidator $references,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * @return Registration[]
     * @throws DeedException deed_not_found
     */
    public function list(int $deedId, int $notaryId): array
    {
        if ($this->deeds->findHeaderByIdAndNotary($deedId, $notaryId) === null) {
            throw new DeedException(DeedError::DeedNotFound);
        }

        return $this->registrations->findByDeed($deedId);
    }

    /**
     * @throws DeedException deed_not_found | registration_limit_exceeded
     */
    public function create(int $deedId, RegistrationInput $input, int $notaryId, int $userId): Registration
    {
        $id = $this->transactions->transactional(function () use ($deedId, $input, $notaryId, $userId): int {
            $deed = $this->lockDeed($deedId, $notaryId);

            // Counted after the lock: concurrent POSTs on the same deed are serialized.
            // The limit comes from the deed's current type even when that type is now inactive.
            $type = $deed->deedTypeId !== null
                ? $this->references->findDeedType($deed->deedTypeId, $notaryId)
                : null;
            $this->references->assertRegistrationLimit($type, $this->registrations->countByDeed($deedId) + 1);

            $id = $this->registrations->insert($deedId, $input);
            $this->deeds->touch($deedId, $notaryId, $userId, new DateTimeImmutable());

            return $id;
        });

        return $this->registrations->findByIdAndDeed($id, $deedId)
            ?? throw new \LogicException('Registration not found after insert');
    }

    /**
     * @throws DeedException deed_not_found | registration_not_found
     */
    public function update(int $deedId, int $id, RegistrationInput $input, int $notaryId, int $userId): Registration
    {
        $this->transactions->transactional(function () use ($deedId, $id, $input, $notaryId, $userId): void {
            $this->lockDeed($deedId, $notaryId);

            if ($this->registrations->findByIdAndDeed($id, $deedId) === null) {
                throw new DeedException(DeedError::RegistrationNotFound);
            }

            $this->registrations->update($id, $deedId, $input);
            $this->deeds->touch($deedId, $notaryId, $userId, new DateTimeImmutable());
        });

        return $this->registrations->findByIdAndDeed($id, $deedId)
            ?? throw new DeedException(DeedError::RegistrationNotFound);
    }

    /**
     * Physical delete: registrations have no status column.
     *
     * @throws DeedException deed_not_found | registration_not_found
     */
    public function delete(int $deedId, int $id, int $notaryId, int $userId): void
    {
        $this->transactions->transactional(function () use ($deedId, $id, $notaryId, $userId): void {
            $this->lockDeed($deedId, $notaryId);

            if ($this->registrations->findByIdAndDeed($id, $deedId) === null) {
                throw new DeedException(DeedError::RegistrationNotFound);
            }

            $this->registrations->delete($id, $deedId);
            $this->deeds->touch($deedId, $notaryId, $userId, new DateTimeImmutable());
        });
    }

    private function lockDeed(int $deedId, int $notaryId): DeedLock
    {
        return $this->deeds->lockForUpdate($deedId, $notaryId)
            ?? throw new DeedException(DeedError::DeedNotFound);
    }
}
