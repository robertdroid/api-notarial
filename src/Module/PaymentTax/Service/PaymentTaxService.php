<?php

declare(strict_types=1);

namespace App\Module\PaymentTax\Service;

use App\Module\PaymentTax\DTO\CreatePaymentTaxRequest;
use App\Module\PaymentTax\DTO\UpdatePaymentTaxRequest;
use App\Module\PaymentTax\Entity\PaymentTax;
use App\Module\PaymentTax\Repository\PaymentTaxRepository;

final class PaymentTaxService
{
    private const DUPLICATE_NAME = 'Tax name already exists';

    public function __construct(
        private readonly PaymentTaxRepository $repository
    ) {
    }

    /**
     * @return PaymentTax[]
     */
    public function getAll(int $notaryId, ?int $status, ?string $name): array
    {
        return $this->repository->findAllByNotary($notaryId, $status, $name);
    }

    public function getById(int $id, int $notaryId): ?PaymentTax
    {
        return $this->repository->findByIdAndNotary($id, $notaryId);
    }

    public function create(CreatePaymentTaxRequest $request, int $notaryId): true|string
    {
        if ($this->repository->existsByNameAndNotary($request->taxName, $notaryId)) {
            return self::DUPLICATE_NAME;
        }

        $this->repository->create(new PaymentTax(
            id: 0,
            notaryId: $notaryId,
            name: $request->taxName,
            statusId: $request->status,
        ));

        return true;
    }

    /**
     * @return true|string|null null when the tax does not exist in this notary
     */
    public function update(int $id, UpdatePaymentTaxRequest $request, int $notaryId): true|string|null
    {
        $existing = $this->repository->findByIdAndNotary($id, $notaryId);
        if ($existing === null) {
            return null;
        }

        if (
            $request->taxName !== null
            && $this->repository->existsByNameAndNotary($request->taxName, $notaryId, $id)
        ) {
            return self::DUPLICATE_NAME;
        }

        $this->repository->update(new PaymentTax(
            id: $id,
            notaryId: $notaryId,
            name: $request->taxName ?? $existing->name,
            statusId: $request->status ?? $existing->statusId,
        ));

        return true;
    }
}
