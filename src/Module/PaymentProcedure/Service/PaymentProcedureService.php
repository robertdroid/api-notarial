<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedure\Service;

use App\Module\PaymentProcedure\DTO\CreatePaymentProcedureRequest;
use App\Module\PaymentProcedure\DTO\UpdatePaymentProcedureRequest;
use App\Module\PaymentProcedure\Entity\PaymentProcedure;
use App\Module\PaymentProcedure\Repository\PaymentProcedureRepository;

final class PaymentProcedureService
{
    private const DUPLICATE_NAME = 'Procedure name already exists';

    public function __construct(
        private readonly PaymentProcedureRepository $repository
    ) {
    }

    /**
     * @return PaymentProcedure[]
     */
    public function getAll(int $notaryId, ?int $status, ?string $name): array
    {
        return $this->repository->findAllByNotary($notaryId, $status, $name);
    }

    public function getById(int $id, int $notaryId): ?PaymentProcedure
    {
        return $this->repository->findByIdAndNotary($id, $notaryId);
    }

    public function create(CreatePaymentProcedureRequest $request, int $notaryId): true|string
    {
        if ($this->repository->existsByNameAndNotary($request->procedureName, $notaryId)) {
            return self::DUPLICATE_NAME;
        }

        $this->repository->create(new PaymentProcedure(
            id: 0,
            notaryId: $notaryId,
            name: $request->procedureName,
            statusId: $request->status,
        ));

        return true;
    }

    /**
     * @return true|string|null null when the procedure does not exist in this notary
     */
    public function update(int $id, UpdatePaymentProcedureRequest $request, int $notaryId): true|string|null
    {
        $existing = $this->repository->findByIdAndNotary($id, $notaryId);
        if ($existing === null) {
            return null;
        }

        // Uniqueness is checked only when the name actually changes, so legacy
        // duplicates (e.g. "RATIFICACION") do not block unrelated updates.
        if (
            $request->procedureName !== null
            && mb_strtolower($request->procedureName) !== mb_strtolower($existing->name)
            && $this->repository->existsByNameAndNotary($request->procedureName, $notaryId, $id)
        ) {
            return self::DUPLICATE_NAME;
        }

        $this->repository->update(new PaymentProcedure(
            id: $id,
            notaryId: $notaryId,
            name: $request->procedureName ?? $existing->name,
            statusId: $request->status ?? $existing->statusId,
        ));

        return true;
    }
}
