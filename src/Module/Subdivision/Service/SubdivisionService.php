<?php

declare(strict_types=1);

namespace App\Module\Subdivision\Service;

use App\Module\Subdivision\Repository\SubdivisionRepository;
use App\Module\Subdivision\Entity\Subdivision;
use App\Module\Subdivision\DTO\CreateSubdivisionRequest;
use App\Module\Subdivision\DTO\UpdateSubdivisionRequest;
use DateTime;

class SubdivisionService
{
    public function __construct(
        private readonly SubdivisionRepository $repository
    ) {}

    public function getAll(int $notaryId, ?int $status, ?string $name): array
    {
        return $this->repository->findAllByNotary($notaryId, $status, $name);
    }

    public function getById(int $id, int $notaryId): ?Subdivision
    {
        return $this->repository->findByIdAndNotary($id, $notaryId);
    }

    public function create(CreateSubdivisionRequest $request, int $notaryId, int $userId): bool|string
    {
        $name = trim($request->name);

        if ($this->repository->existsByNameAndNotary($name, $notaryId)) {
            return 'Subdivision name already exists';
        }

        $subdivision = new Subdivision(
            id: 0,
            notaryId: $notaryId,
            name: $name,
            userId: $userId,
            modificationDate: new DateTime(),
            statusId: $request->status,
        );

        $this->repository->create($subdivision);
        return true;
    }

    public function update(int $id, UpdateSubdivisionRequest $request, int $notaryId, int $userId): bool|string|null
    {
        $existing = $this->repository->findByIdAndNotary($id, $notaryId);

        if ($existing === null) {
            return null;
        }

        $name = trim($request->name);

        if ($this->repository->existsByNameAndNotary($name, $notaryId, $id)) {
            return 'Subdivision name already exists';
        }

        $subdivision = new Subdivision(
            id: $id,
            notaryId: $notaryId,
            name: $name,
            userId: $userId,
            modificationDate: new DateTime(),
            statusId: $request->status,
        );

        $this->repository->update($subdivision);
        return true;
    }
}
