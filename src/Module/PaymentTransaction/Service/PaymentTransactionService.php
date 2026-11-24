<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\Service;

use App\Core\Database\TransactionManager;
use App\Module\PaymentClient\Entity\PaymentClient;
use App\Module\PaymentClient\Repository\PaymentClientRepository;
use App\Module\PaymentProcedure\Entity\PaymentProcedure;
use App\Module\PaymentProcedure\Repository\PaymentProcedureRepository;
use App\Module\PaymentProcedureTax\Repository\PaymentProcedureTaxRepository;
use App\Module\PaymentTax\Entity\PaymentTax;
use App\Module\PaymentTax\Repository\PaymentTaxRepository;
use App\Module\PaymentTransaction\DTO\CreateTransactionRequest;
use App\Module\PaymentTransaction\DTO\TransactionDetailItem;
use App\Module\PaymentTransaction\DTO\UpdateTransactionRequest;
use App\Module\PaymentTransaction\Entity\PaymentTransaction;
use App\Module\PaymentTransaction\Exception\DuplicateTransactionUuidException;
use App\Module\PaymentTransaction\Exception\TransactionError;
use App\Module\PaymentTransaction\Exception\TransactionException;
use App\Module\PaymentTransaction\Repository\PaymentTransactionRepository;

final class PaymentTransactionService
{
    public function __construct(
        private readonly PaymentTransactionRepository $repository,
        private readonly PaymentClientRepository $clientRepository,
        private readonly PaymentProcedureRepository $procedureRepository,
        private readonly PaymentTaxRepository $taxRepository,
        private readonly PaymentProcedureTaxRepository $procedureTaxRepository,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function getById(int $id, int $notaryId): PaymentTransaction
    {
        return $this->repository->findByIdAndNotary($id, $notaryId)
            ?? throw new TransactionException(TransactionError::TransactionNotFound);
    }

    public function getByUuid(string $uuid, int $notaryId): PaymentTransaction
    {
        return $this->repository->findByUuidAndNotary($uuid, $notaryId)
            ?? throw new TransactionException(TransactionError::TransactionNotFound);
    }

    public function create(CreateTransactionRequest $dto, int $notaryId): CreateTransactionResult
    {
        // Idempotency first: a retry must succeed even if the catalog changed meanwhile (ADR-003)
        $existing = $this->repository->findByUuidAndNotary($dto->uuid, $notaryId);
        if ($existing !== null) {
            return new CreateTransactionResult($existing, created: false);
        }

        $this->requireActiveClient($dto->clientId, $notaryId);
        $this->requireActiveProcedure($dto->procedureId, $notaryId);

        [$details, $taxes] = $this->resolveDetailsAndTaxes($dto->procedureId, $notaryId, $dto->details, $dto->taxes);
        $total = round($dto->fee + $taxes, 2);

        try {
            $id = $this->transactions->transactional(
                function () use ($dto, $notaryId, $details, $taxes, $total): int {
                    $id = $this->repository->insert($dto, $notaryId, $taxes, $total);
                    $this->repository->insertDetails($id, $details);

                    return $id;
                }
            );
        } catch (DuplicateTransactionUuidException) {
            // Lost the race against a concurrent POST with the same uuid: return the winner
            $winner = $this->repository->findByUuidAndNotary($dto->uuid, $notaryId)
                ?? throw new \LogicException('Duplicate uuid reported but transaction not found');

            return new CreateTransactionResult($winner, created: false);
        }

        return new CreateTransactionResult($this->getById($id, $notaryId), created: true);
    }

    public function update(int $id, UpdateTransactionRequest $dto, int $notaryId): PaymentTransaction
    {
        $this->transactions->transactional(function () use ($id, $dto, $notaryId): void {
            $current = $this->repository->findByIdAndNotary($id, $notaryId, forUpdate: true)
                ?? throw new TransactionException(TransactionError::TransactionNotFound);

            if ($dto->clientId !== null && $dto->clientId !== $current->clientId) {
                throw new TransactionException(TransactionError::CannotChangeClient);
            }
            if ($dto->uuid !== null && $dto->uuid !== $current->uuid) {
                throw new TransactionException(TransactionError::CannotChangeUuid);
            }
            if ($dto->procedureId !== $current->procedureId) {
                $this->requireActiveProcedure($dto->procedureId, $notaryId);
            }

            [$details, $taxes] = $this->resolveDetailsAndTaxes(
                $dto->procedureId,
                $notaryId,
                $dto->details,
                $dto->taxes,
                $current->detailTaxIds(),
            );

            $this->repository->deleteDetails($id);
            $this->repository->insertDetails($id, $details);
            $this->repository->update($id, $notaryId, $dto, $taxes, round($dto->fee + $taxes, 2));
        });

        return $this->getById($id, $notaryId);
    }

    private function requireActiveClient(int $clientId, int $notaryId): void
    {
        $client = $this->clientRepository->findByIdAndNotary($clientId, $notaryId)
            ?? throw new TransactionException(TransactionError::ClientNotFound);

        if ($client->statusId !== PaymentClient::STATUS_ACTIVE) {
            throw new TransactionException(TransactionError::ClientInactive);
        }
    }

    private function requireActiveProcedure(int $procedureId, int $notaryId): void
    {
        $procedure = $this->procedureRepository->findByIdAndNotary($procedureId, $notaryId)
            ?? throw new TransactionException(TransactionError::ProcedureNotFound);

        if ($procedure->statusId !== PaymentProcedure::STATUS_ACTIVE) {
            throw new TransactionException(TransactionError::ProcedureInactive);
        }
    }

    /**
     * Manual mode (procedure without active taxes): the request decides taxes and details are ignored.
     * Calculated mode: taxes is the sum of the valid details and the request value is ignored (ADR-002).
     *
     * @param TransactionDetailItem[] $items
     * @param int[] $keepTaxIds taxes already on the transaction: kept even if inactive now (ADR-006)
     * @return array{0: TransactionDetailItem[], 1: float} valid details and the taxes amount
     */
    private function resolveDetailsAndTaxes(
        int $procedureId,
        int $notaryId,
        array $items,
        float $requestTaxes,
        array $keepTaxIds = [],
    ): array {
        $isManual = $this->procedureTaxRepository->findActiveByProcedure($procedureId, $notaryId) === [];

        if ($isManual) {
            return [[], round($requestTaxes, 2)];
        }

        $catalog = $this->taxRepository->findByIdsAndNotary(
            array_map(static fn (TransactionDetailItem $i): int => $i->taxId, $items),
            $notaryId
        );

        $valid = [];
        $sum = 0.0;
        foreach ($items as $item) {
            $tax = $catalog[$item->taxId] ?? null;

            // Repeated taxId (first wins) or unknown / other notary
            if (isset($valid[$item->taxId]) || $tax === null) {
                continue;
            }

            // Inactive and new to this transaction
            if ($tax->statusId !== PaymentTax::STATUS_ACTIVE && !in_array($item->taxId, $keepTaxIds, true)) {
                continue;
            }

            $valid[$item->taxId] = $item;
            $sum += $item->subtotal();
        }

        return [array_values($valid), round($sum, 2)];
    }
}
