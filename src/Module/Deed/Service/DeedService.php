<?php

declare(strict_types=1);

namespace App\Module\Deed\Service;

use App\Core\Database\TransactionManager;
use App\Module\Deed\DTO\CreateDeedRequest;
use App\Module\Deed\DTO\DeedListQuery;
use App\Module\Deed\DTO\UpdateDeedRequest;
use App\Module\Deed\Entity\Deed;
use App\Module\Deed\Entity\DeedListItem;
use App\Module\Deed\Exception\DeedError;
use App\Module\Deed\Exception\DeedException;
use App\Module\Deed\Repository\DeedRepository;
use App\Module\Deed\Repository\ParticipantRepository;
use App\Module\Deed\Repository\RegistrationRepository;
use App\Shared\Pagination\PaginatedResult;
use DateTimeImmutable;

class DeedService
{
    public const PER_PAGE = 100;

    public function __construct(
        private readonly DeedRepository $repository,
        private readonly ParticipantRepository $participants,
        private readonly RegistrationRepository $registrations,
        private readonly DeedReferenceValidator $references,
        private readonly TransactionManager $transactions,
    ) {}

    /**
     * @return PaginatedResult<DeedListItem>
     */
    public function list(int $notaryId, DeedListQuery $query): PaginatedResult
    {
        $offset = ($query->page - 1) * self::PER_PAGE;
        $total = $this->repository->countByNotary($notaryId, $query->words);

        $items = $offset < $total
            ? $this->repository->findPageByNotary($notaryId, $query->words, self::PER_PAGE, $offset)
            : [];

        return new PaginatedResult($items, $total, $query->page, self::PER_PAGE);
    }

    /**
     * @throws DeedException deed_not_found
     */
    public function getById(int $id, int $notaryId): Deed
    {
        $header = $this->repository->findHeaderByIdAndNotary($id, $notaryId)
            ?? throw new DeedException(DeedError::DeedNotFound);

        // The header proved the deed belongs to the notary: children are read by deed id (3 queries in total)
        return Deed::fromRow(
            $header,
            $this->participants->findActiveByDeed($id),
            $this->registrations->findByDeed($id),
        );
    }

    /**
     * @throws DeedException act_unavailable | deed_type_unavailable | subdivision_unavailable
     *                       | subdivision_not_allowed | registration_limit_exceeded
     */
    public function create(CreateDeedRequest $dto, int $notaryId, int $userId): Deed
    {
        $fields = $dto->fields;

        $this->references->requireActiveAct($fields->actId, $notaryId);
        $type = $this->references->requireActiveDeedType($fields->deedTypeId, $notaryId);
        if ($fields->subdivisionId !== null) {
            $this->references->requireActiveSubdivision($fields->subdivisionId, $notaryId);
        }
        $this->references->assertSubdivisionAllowed($type, $fields->subdivisionId);
        $this->references->assertRegistrationLimit($type, count($dto->registrations));

        $id = $this->transactions->transactional(function () use ($dto, $notaryId, $userId): int {
            $id = $this->repository->insert($notaryId, $dto->fields, $userId, new DateTimeImmutable());
            $this->participants->insertMany($id, $dto->participants);
            $this->registrations->insertMany($id, $dto->registrations);

            return $id;
        });

        return $this->getById($id, $notaryId);
    }

    /**
     * Full replacement of the editable fields. Catalog rules only apply to what changes,
     * so legacy deeds that point to inactive catalogs can still be edited.
     *
     * @throws DeedException deed_not_found | act_unavailable | deed_type_unavailable
     *                       | subdivision_unavailable | subdivision_not_allowed | registration_limit_exceeded
     */
    public function update(int $id, UpdateDeedRequest $dto, int $notaryId, int $userId): Deed
    {
        $fields = $dto->fields;

        $this->transactions->transactional(function () use ($id, $fields, $notaryId, $userId): void {
            $current = $this->repository->lockForUpdate($id, $notaryId)
                ?? throw new DeedException(DeedError::DeedNotFound);

            $actChanged = $fields->actId !== $current->actId;
            $typeChanged = $fields->deedTypeId !== $current->deedTypeId;
            $subdivisionChanged = $fields->subdivisionId !== $current->subdivisionId;

            if ($actChanged) {
                $this->references->requireActiveAct($fields->actId, $notaryId);
            }
            if ($subdivisionChanged && $fields->subdivisionId !== null) {
                $this->references->requireActiveSubdivision($fields->subdivisionId, $notaryId);
            }

            $type = $typeChanged
                ? $this->references->requireActiveDeedType($fields->deedTypeId, $notaryId)
                : $this->references->findDeedType($fields->deedTypeId, $notaryId);

            if (($typeChanged || $subdivisionChanged) && $type !== null) {
                $this->references->assertSubdivisionAllowed($type, $fields->subdivisionId);
            }
            if ($typeChanged) {
                $this->references->assertRegistrationLimit($type, $this->registrations->countByDeed($id));
            }

            // Never touches the finished flag (legacy bug fix)
            $this->repository->update($id, $notaryId, $fields, $userId, new DateTimeImmutable());
        });

        return $this->getById($id, $notaryId);
    }

    /**
     * Irreversible: there is no way back to "not finished".
     *
     * @throws DeedException deed_not_found | deed_already_finished
     */
    public function finish(int $id, int $notaryId, int $userId): Deed
    {
        $this->transactions->transactional(function () use ($id, $notaryId, $userId): void {
            $lock = $this->repository->lockForUpdate($id, $notaryId)
                ?? throw new DeedException(DeedError::DeedNotFound);

            if ($lock->isFinished) {
                throw new DeedException(DeedError::DeedAlreadyFinished);
            }

            $this->repository->markFinished($id, $notaryId, $userId, new DateTimeImmutable());
        });

        return $this->getById($id, $notaryId);
    }
}
