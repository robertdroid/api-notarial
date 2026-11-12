<?php

declare(strict_types=1);

namespace App\Module\Deed\Service;

use App\Core\Database\TransactionManager;
use App\Module\Deed\DTO\ParticipantInput;
use App\Module\Deed\Entity\DeedLock;
use App\Module\Deed\Entity\Participant;
use App\Module\Deed\Exception\DeedError;
use App\Module\Deed\Exception\DeedException;
use App\Module\Deed\Repository\DeedRepository;
use App\Module\Deed\Repository\ParticipantRepository;
use DateTimeImmutable;

class ParticipantService
{
    public function __construct(
        private readonly DeedRepository $deeds,
        private readonly ParticipantRepository $participants,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * @return Participant[] Active participants only
     * @throws DeedException deed_not_found
     */
    public function list(int $deedId, int $notaryId): array
    {
        if ($this->deeds->findHeaderByIdAndNotary($deedId, $notaryId) === null) {
            throw new DeedException(DeedError::DeedNotFound);
        }

        return $this->participants->findActiveByDeed($deedId);
    }

    /**
     * @throws DeedException deed_not_found
     */
    public function create(int $deedId, ParticipantInput $input, int $notaryId, int $userId): Participant
    {
        $id = $this->transactions->transactional(function () use ($deedId, $input, $notaryId, $userId): int {
            $this->lockDeed($deedId, $notaryId);

            $id = $this->participants->insert($deedId, $input);
            $this->deeds->touch($deedId, $notaryId, $userId, new DateTimeImmutable());

            return $id;
        });

        return $this->participants->findByIdAndDeed($id, $deedId)
            ?? throw new \LogicException('Participant not found after insert');
    }

    /**
     * @throws DeedException deed_not_found | participant_not_found | participant_inactive
     */
    public function update(int $deedId, int $id, ParticipantInput $input, int $notaryId, int $userId): Participant
    {
        $this->transactions->transactional(function () use ($deedId, $id, $input, $notaryId, $userId): void {
            $this->lockDeed($deedId, $notaryId);

            $participant = $this->participants->findByIdAndDeed($id, $deedId)
                ?? throw new DeedException(DeedError::ParticipantNotFound);
            if (!$participant->isActive()) {
                throw new DeedException(DeedError::ParticipantInactive);
            }

            $this->participants->update($id, $deedId, $input);
            $this->deeds->touch($deedId, $notaryId, $userId, new DateTimeImmutable());
        });

        return $this->participants->findByIdAndDeed($id, $deedId)
            ?? throw new DeedException(DeedError::ParticipantNotFound);
    }

    /**
     * Soft delete: the participant stays in the table as inactive.
     *
     * @throws DeedException deed_not_found | participant_not_found | participant_already_inactive
     */
    public function deactivate(int $deedId, int $id, int $notaryId, int $userId): void
    {
        $this->transactions->transactional(function () use ($deedId, $id, $notaryId, $userId): void {
            $this->lockDeed($deedId, $notaryId);

            $participant = $this->participants->findByIdAndDeed($id, $deedId)
                ?? throw new DeedException(DeedError::ParticipantNotFound);
            if (!$participant->isActive()) {
                throw new DeedException(DeedError::ParticipantAlreadyInactive);
            }

            $this->participants->deactivate($id, $deedId);
            $this->deeds->touch($deedId, $notaryId, $userId, new DateTimeImmutable());
        });
    }

    private function lockDeed(int $deedId, int $notaryId): DeedLock
    {
        return $this->deeds->lockForUpdate($deedId, $notaryId)
            ?? throw new DeedException(DeedError::DeedNotFound);
    }
}
