<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedureTax\Service;

use App\Module\PaymentProcedure\Entity\PaymentProcedure;
use App\Module\PaymentProcedure\Repository\PaymentProcedureRepository;
use App\Module\PaymentProcedureTax\DTO\ProcedureTaxItem;
use App\Module\PaymentProcedureTax\Entity\PaymentProcedureTax;
use App\Module\PaymentProcedureTax\Exception\InvalidProcedureTaxItemsException;
use App\Module\PaymentProcedureTax\Exception\ProcedureTaxError;
use App\Module\PaymentProcedureTax\Exception\ProcedureTaxException;
use App\Module\PaymentProcedureTax\Repository\PaymentProcedureTaxRepository;
use App\Module\PaymentTax\Entity\PaymentTax;
use App\Module\PaymentTax\Repository\PaymentTaxRepository;

final class PaymentProcedureTaxService
{
    public function __construct(
        private readonly PaymentProcedureTaxRepository $repository,
        private readonly PaymentProcedureRepository $procedureRepository,
        private readonly PaymentTaxRepository $taxRepository,
    ) {
    }

    /**
     * @return PaymentProcedureTax[]
     * @throws ProcedureTaxException
     */
    public function getByProcedure(int $procedureId, int $notaryId): array
    {
        $this->requireProcedure($procedureId, $notaryId, requireActive: false);

        return $this->repository->findActiveByProcedure($procedureId, $notaryId);
    }

    /**
     * All-or-nothing: nothing is written if any item is invalid.
     *
     * @param ProcedureTaxItem[] $items
     * @throws ProcedureTaxException
     * @throws InvalidProcedureTaxItemsException
     */
    public function add(int $procedureId, array $items, int $notaryId): void
    {
        $this->requireProcedure($procedureId, $notaryId, requireActive: true);

        $taxIds = array_map(static fn (ProcedureTaxItem $item): int => $item->taxId, $items);
        $taxes = $this->taxRepository->findByIdsAndNotary($taxIds, $notaryId);
        $statuses = $this->repository->findStatuses($procedureId, $taxIds);

        $errors = [];
        foreach ($items as $index => $item) {
            $tax = $taxes[$item->taxId] ?? null;
            $error = match (true) {
                $tax === null => ProcedureTaxError::TaxNotFound,
                $tax->statusId !== PaymentTax::STATUS_ACTIVE => ProcedureTaxError::TaxInactive,
                ($statuses[$item->taxId] ?? null) === PaymentProcedureTax::STATUS_ACTIVE
                    => ProcedureTaxError::AlreadyAssigned,
                default => null,
            };

            if ($error !== null) {
                $errors[$index] = $error;
            }
        }

        if ($errors !== []) {
            throw new InvalidProcedureTaxItemsException($errors);
        }

        $this->repository->upsertMany($procedureId, $items);
    }

    /**
     * @throws ProcedureTaxException
     */
    public function updateDefaultValue(int $procedureId, int $taxId, float $value, int $notaryId): void
    {
        $this->requireProcedure($procedureId, $notaryId, requireActive: true);

        $tax = $this->taxRepository->findByIdAndNotary($taxId, $notaryId)
            ?? throw new ProcedureTaxException(ProcedureTaxError::TaxNotFound);

        if ($tax->statusId !== PaymentTax::STATUS_ACTIVE) {
            throw new ProcedureTaxException(ProcedureTaxError::TaxInactive);
        }

        $this->requireActiveAssignment($procedureId, $taxId);

        $this->repository->updateDefaultValue($procedureId, $taxId, $value, $notaryId);
    }

    /**
     * The catalog tax status is not checked so stale assignments can be cleaned up.
     *
     * @throws ProcedureTaxException
     */
    public function remove(int $procedureId, int $taxId, int $notaryId): void
    {
        $this->requireProcedure($procedureId, $notaryId, requireActive: false);
        $this->requireActiveAssignment($procedureId, $taxId);

        $this->repository->deactivate($procedureId, $taxId, $notaryId);
    }

    private function requireProcedure(int $procedureId, int $notaryId, bool $requireActive): void
    {
        $procedure = $this->procedureRepository->findByIdAndNotary($procedureId, $notaryId)
            ?? throw new ProcedureTaxException(ProcedureTaxError::ProcedureNotFound);

        if ($requireActive && $procedure->statusId !== PaymentProcedure::STATUS_ACTIVE) {
            throw new ProcedureTaxException(ProcedureTaxError::ProcedureInactive);
        }
    }

    private function requireActiveAssignment(int $procedureId, int $taxId): void
    {
        $status = $this->repository->findStatuses($procedureId, [$taxId])[$taxId] ?? null;

        if ($status !== PaymentProcedureTax::STATUS_ACTIVE) {
            throw new ProcedureTaxException(ProcedureTaxError::NotAssigned);
        }
    }
}
