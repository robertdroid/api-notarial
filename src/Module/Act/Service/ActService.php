<?php

declare(strict_types=1);

namespace App\Module\Act\Service;

use App\Module\Act\Repository\ActRepository;
use App\Module\Act\Entity\Act;
use App\Module\Act\DTO\CreateActRequest;
use App\Module\Act\DTO\UpdateActRequest;
use DateTime;

class ActService
{
    public function __construct(
        private readonly ActRepository $repository
    ) {}

    public function getAll(int $notaryId, ?int $status, ?string $name): array
    {
        return $this->repository->findAllByNotary($notaryId, $status, $name);
    }

    public function getById(int $id, int $notaryId): ?Act
    {
        return $this->repository->findByIdAndNotary($id, $notaryId);
    }

    public function create(CreateActRequest $request, int $notaryId, int $userId): bool|string
    {
        $name = trim($request->actName);

        if ($this->repository->existsByNameAndNotary($name, $notaryId)) {
            return 'Act name already exists';
        }

        $act = new Act(
            id: 0,
            notaryId: $notaryId,
            name: $name,
            description: trim($request->actDescription),
            userId: $userId,
            modificationDate: new DateTime(),
            statusId: $request->status,
        );

        $this->repository->create($act);
        return true;
    }

    public function update(int $id, UpdateActRequest $request, int $notaryId, int $userId): bool|string|null
    {
        $existing = $this->repository->findByIdAndNotary($id, $notaryId);

        if ($existing === null) {
            return null;
        }

        $name = trim($request->actName);

        if ($this->repository->existsByNameAndNotary($name, $notaryId, $id)) {
            return 'Act name already exists';
        }

        $act = new Act(
            id: $id,
            notaryId: $notaryId,
            name: $name,
            description: trim($request->actDescription),
            userId: $userId,
            modificationDate: new DateTime(),
            statusId: $request->status,
        );

        $this->repository->update($act);
        return true;
    }
}
