<?php

declare(strict_types=1);

namespace App\Module\PaymentClient\Service;

use App\Module\PaymentClient\DTO\CreatePaymentClientRequest;
use App\Module\PaymentClient\DTO\UpdatePaymentClientRequest;
use App\Module\PaymentClient\Entity\PaymentClient;
use App\Module\PaymentClient\Repository\PaymentClientRepository;

final class PaymentClientService
{
    public function __construct(
        private readonly PaymentClientRepository $repository
    ) {
    }

    /**
     * @return PaymentClient[]
     */
    public function getAll(int $notaryId, ?int $status, ?string $name): array
    {
        return $this->repository->findAllByNotary($notaryId, $status, $name);
    }

    public function getById(int $id, int $notaryId): ?PaymentClient
    {
        return $this->repository->findByIdAndNotary($id, $notaryId);
    }

    public function create(CreatePaymentClientRequest $request, int $notaryId): void
    {
        $this->repository->create(new PaymentClient(
            id: 0,
            notaryId: $notaryId,
            fullName: $request->fullName,
            phone: $request->phone,
            email: $request->email,
            statusId: $request->status,
        ));
    }

    /**
     * @return bool false when the client does not exist in this notary
     */
    public function update(int $id, UpdatePaymentClientRequest $request, int $notaryId): bool
    {
        $existing = $this->repository->findByIdAndNotary($id, $notaryId);
        if ($existing === null) {
            return false;
        }

        // '' is not null, so an explicit empty value overrides the stored one.
        $this->repository->update(new PaymentClient(
            id: $id,
            notaryId: $notaryId,
            fullName: $request->fullName ?? $existing->fullName,
            phone: $request->phone ?? $existing->phone,
            email: $request->email ?? $existing->email,
            statusId: $request->status ?? $existing->statusId,
        ));

        return true;
    }
}
