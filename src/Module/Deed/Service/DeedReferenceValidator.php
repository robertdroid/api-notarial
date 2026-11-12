<?php

declare(strict_types=1);

namespace App\Module\Deed\Service;

use App\Module\Act\Repository\ActRepository;
use App\Module\DeedType\Entity\DeedType;
use App\Module\DeedType\Repository\DeedTypeRepository;
use App\Module\Deed\Exception\DeedError;
use App\Module\Deed\Exception\DeedException;
use App\Module\Subdivision\Repository\SubdivisionRepository;

/**
 * Checks that the catalogs a deed points to exist, belong to the notary and are active,
 * and applies the rules of the deed type. Shared by DeedService and RegistrationService.
 */
class DeedReferenceValidator
{
    private const STATUS_ACTIVE = 1;

    public function __construct(
        private readonly ActRepository $acts,
        private readonly DeedTypeRepository $deedTypes,
        private readonly SubdivisionRepository $subdivisions,
    ) {
    }

    public function requireActiveAct(int $id, int $notaryId): void
    {
        $act = $this->acts->findByIdAndNotary($id, $notaryId);

        if ($act === null || $act->statusId !== self::STATUS_ACTIVE) {
            throw new DeedException(DeedError::ActUnavailable);
        }
    }

    public function requireActiveDeedType(int $id, int $notaryId): DeedType
    {
        $type = $this->deedTypes->findByIdAndNotary($id, $notaryId);

        if ($type === null || $type->statusId !== self::STATUS_ACTIVE) {
            throw new DeedException(DeedError::DeedTypeUnavailable);
        }

        return $type;
    }

    public function requireActiveSubdivision(int $id, int $notaryId): void
    {
        $subdivision = $this->subdivisions->findByIdAndNotary($id, $notaryId);

        if ($subdivision === null || $subdivision->statusId !== self::STATUS_ACTIVE) {
            throw new DeedException(DeedError::SubdivisionUnavailable);
        }
    }

    /** Looks the type up without checking its status (the deed may point to a type that is now inactive). */
    public function findDeedType(int $id, int $notaryId): ?DeedType
    {
        return $this->deedTypes->findByIdAndNotary($id, $notaryId);
    }

    public function assertSubdivisionAllowed(DeedType $type, ?int $subdivisionId): void
    {
        if ($subdivisionId !== null && $type->enableSubdivisions !== 1) {
            throw new DeedException(DeedError::SubdivisionNotAllowed, ['deedTypeId' => $type->id]);
        }
    }

    /**
     * @param int $count Registrations the deed would have after the operation.
     *                   A type without limit (max <= 0) or a missing type never fails.
     */
    public function assertRegistrationLimit(?DeedType $type, int $count): void
    {
        if ($type === null || $type->maxInscriptions <= 0 || $count <= $type->maxInscriptions) {
            return;
        }

        throw new DeedException(DeedError::RegistrationLimitExceeded, [
            'maxRegistrations' => $type->maxInscriptions,
            'currentRegistrations' => $count,
        ]);
    }
}
