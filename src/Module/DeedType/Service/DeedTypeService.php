<?php

declare(strict_types=1);

namespace App\Module\DeedType\Service;

use App\Module\DeedType\Repository\DeedTypeRepository;
use App\Module\DeedType\Entity\DeedType;
use App\Module\DeedType\DTO\CreateDeedTypeRequest;
use App\Module\DeedType\DTO\UpdateDeedTypeRequest;
use DateTime;

class DeedTypeService
{
    public function __construct(
        private readonly DeedTypeRepository $repository
    ) {}

    public function getAll(int $notaryId, ?int $status, ?string $name): array
    {
        return $this->repository->findAllByNotary($notaryId, $status, $name);
    }

    public function getById(int $id, int $notaryId): ?DeedType
    {
        return $this->repository->findByIdAndNotary($id, $notaryId);
    }

    public function create(CreateDeedTypeRequest $request, int $notaryId, int $userId): bool|string
    {
        $name = trim($request->name);

        if ($this->repository->existsByNameAndNotary($name, $notaryId)) {
            return 'Deed type name already exists';
        }

        $deedType = new DeedType(
            id: 0,
            notaryId: $notaryId,
            name: $name,
            description: trim($request->description),
            maxInscriptions: $request->maxInscriptions,
            enableSubdivisions: $request->enableSubdivisions ? 1 : 0,
            userId: $userId,
            modificationDate: new DateTime(),
            statusId: $request->status,
        );

        $this->repository->create($deedType);
        return true;
    }

    public function update(int $id, UpdateDeedTypeRequest $request, int $notaryId, int $userId): bool|string|null
    {
        $existing = $this->repository->findByIdAndNotary($id, $notaryId);

        if ($existing === null) {
            return null;
        }

        $name = trim($request->name);

        if ($this->repository->existsByNameAndNotary($name, $notaryId, $id)) {
            return 'Deed type name already exists';
        }

        $deedType = new DeedType(
            id: $id,
            notaryId: $notaryId,
            name: $name,
            description: trim($request->description),
            maxInscriptions: $request->maxInscriptions,
            enableSubdivisions: $request->enableSubdivisions ? 1 : 0,
            userId: $userId,
            modificationDate: new DateTime(),
            statusId: $request->status,
        );

        $this->repository->update($deedType);
        return true;
    }
}
