<?php

declare(strict_types=1);

namespace App\Module\Payment\Service;

use App\Module\Payment\DTO\CreatePaymentRequest;
use App\Module\Payment\DTO\UpdatePaymentRequest;
use App\Module\Payment\Entity\Payment;
use App\Module\Payment\Exception\DuplicatePaymentUuidException;
use App\Module\Payment\Exception\PaymentError;
use App\Module\Payment\Exception\PaymentException;
use App\Module\Payment\Repository\PaymentRepository;
use App\Module\PaymentClient\Entity\PaymentClient;
use App\Module\PaymentClient\Repository\PaymentClientRepository;

final class PaymentService
{
    public function __construct(
        private readonly PaymentRepository $repository,
        private readonly PaymentClientRepository $clientRepository,
    ) {
    }

    public function getById(int $id, int $notaryId): Payment
    {
        return $this->repository->findByIdAndNotary($id, $notaryId)
            ?? throw new PaymentException(PaymentError::PaymentNotFound);
    }

    public function getByUuid(string $uuid, int $notaryId): Payment
    {
        return $this->repository->findByUuidAndNotary($uuid, $notaryId)
            ?? throw new PaymentException(PaymentError::PaymentNotFound);
    }

    public function create(CreatePaymentRequest $dto, int $notaryId): CreatePaymentResult
    {
        // Idempotency first: a retry must succeed even if the client was deactivated meanwhile (ADR-003)
        $existing = $this->repository->findByUuidAndNotary($dto->uuid, $notaryId);
        if ($existing !== null) {
            return new CreatePaymentResult($existing, created: false);
        }

        $this->requireActiveClient($dto->clientId, $notaryId);

        try {
            $id = $this->repository->insert($dto, $notaryId);
        } catch (DuplicatePaymentUuidException) {
            // Lost the race against a concurrent POST with the same uuid: return the winner
            $winner = $this->repository->findByUuidAndNotary($dto->uuid, $notaryId)
                ?? throw new \LogicException('Duplicate uuid reported but payment not found');

            return new CreatePaymentResult($winner, created: false);
        }

        return new CreatePaymentResult($this->getById($id, $notaryId), created: true);
    }

    public function update(int $id, UpdatePaymentRequest $dto, int $notaryId): Payment
    {
        $current = $this->repository->findByIdAndNotary($id, $notaryId)
            ?? throw new PaymentException(PaymentError::PaymentNotFound);

        if ($dto->clientId !== null && $dto->clientId !== $current->clientId) {
            throw new PaymentException(PaymentError::CannotChangeClient);
        }
        if ($dto->uuid !== null && $dto->uuid !== $current->uuid) {
            throw new PaymentException(PaymentError::CannotChangeUuid);
        }

        // No active-client check on purpose: only POST requires it (scenario 18)
        $this->repository->update(
            $id,
            $notaryId,
            $dto->amount,
            $dto->paymentDate ?? $current->paymentDate,
            $dto->comments ?? $current->comments,
        );

        return $this->getById($id, $notaryId);
    }

    public function delete(int $id, int $notaryId): void
    {
        // No active-client check on purpose: a payment can be deleted whatever the client status (scenario 8)
        if (!$this->repository->delete($id, $notaryId)) {
            throw new PaymentException(PaymentError::PaymentNotFound);
        }
    }

    private function requireActiveClient(int $clientId, int $notaryId): void
    {
        $client = $this->clientRepository->findByIdAndNotary($clientId, $notaryId)
            ?? throw new PaymentException(PaymentError::ClientNotFound);

        if ($client->statusId !== PaymentClient::STATUS_ACTIVE) {
            throw new PaymentException(PaymentError::ClientInactive);
        }
    }
}
