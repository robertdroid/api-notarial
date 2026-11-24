<?php

declare(strict_types=1);

namespace Tests\Module\Payment\Service;

use App\Module\Payment\DTO\CreatePaymentRequest;
use App\Module\Payment\DTO\UpdatePaymentRequest;
use App\Module\Payment\Entity\Payment;
use App\Module\Payment\Exception\DuplicatePaymentUuidException;
use App\Module\Payment\Exception\PaymentError;
use App\Module\Payment\Exception\PaymentException;
use App\Module\Payment\Repository\PaymentRepository;
use App\Module\Payment\Service\PaymentService;
use App\Module\PaymentClient\Entity\PaymentClient;
use App\Module\PaymentClient\Repository\PaymentClientRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PaymentServiceTest extends TestCase
{
    private const NOTARY = 1;
    private const CLIENT = 5;
    private const UUID = 'Us2Gvq';
    private const ID = 19;
    private const INACTIVE = 2;

    private PaymentService $service;
    private PaymentRepository&MockObject $repository;
    private PaymentClientRepository&MockObject $clientRepository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PaymentRepository::class);
        $this->clientRepository = $this->createMock(PaymentClientRepository::class);

        $this->service = new PaymentService($this->repository, $this->clientRepository);
    }

    // ---- helpers ---------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function createDto(array $overrides = []): CreatePaymentRequest
    {
        return CreatePaymentRequest::fromArray($overrides + [
            'clientId' => self::CLIENT, 'uuid' => self::UUID, 'amount' => 5000, 'paymentDate' => '2024-04-02',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function updateDto(array $overrides = []): UpdatePaymentRequest
    {
        return UpdatePaymentRequest::fromArray($overrides + ['amount' => 5500]);
    }

    private function payment(
        float $amount = 5000.0,
        ?string $date = '2024-04-02',
        string $comments = 'Adelanto',
        string $uuid = self::UUID,
    ): Payment {
        return new Payment(self::ID, $uuid, self::NOTARY, self::CLIENT, 'Ana', $amount, $date, $comments);
    }

    private function givenClient(int $status = PaymentClient::STATUS_ACTIVE): void
    {
        $this->clientRepository->method('findByIdAndNotary')
            ->willReturn(new PaymentClient(self::CLIENT, self::NOTARY, 'Ana', '', '', $status));
    }

    // ---- reads -----------------------------------------------------------

    public function testGetByIdReturnsThePayment(): void
    {
        $payment = $this->payment();
        $this->repository->expects($this->once())->method('findByIdAndNotary')
            ->with(self::ID, self::NOTARY)->willReturn($payment);

        $this->assertSame($payment, $this->service->getById(self::ID, self::NOTARY));
    }

    public function testGetByIdThrowsWhenMissingOrFromAnotherNotary(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(null);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Payment not found');
        $this->service->getById(self::ID, 2);
    }

    public function testGetByUuidReturnsThePayment(): void
    {
        $payment = $this->payment();
        $this->repository->expects($this->once())->method('findByUuidAndNotary')
            ->with(self::UUID, self::NOTARY)->willReturn($payment);

        $this->assertSame($payment, $this->service->getByUuid(self::UUID, self::NOTARY));
    }

    public function testGetByUuidThrowsWhenMissing(): void
    {
        $this->repository->method('findByUuidAndNotary')->willReturn(null);

        try {
            $this->service->getByUuid(self::UUID, self::NOTARY);
            $this->fail('PaymentException expected');
        } catch (PaymentException $e) {
            $this->assertSame(PaymentError::PaymentNotFound, $e->error);
        }
    }

    // ---- create ----------------------------------------------------------

    public function testCreateInsertsAndReturnsCreated(): void
    {
        $dto = $this->createDto();
        $created = $this->payment();

        $this->repository->expects($this->once())->method('findByUuidAndNotary')
            ->with(self::UUID, self::NOTARY)->willReturn(null);
        $this->givenClient();
        $this->repository->expects($this->once())->method('insert')
            ->with($dto, self::NOTARY)->willReturn(self::ID);
        $this->repository->expects($this->once())->method('findByIdAndNotary')
            ->with(self::ID, self::NOTARY)->willReturn($created);

        $result = $this->service->create($dto, self::NOTARY);

        $this->assertTrue($result->created);
        $this->assertSame($created, $result->payment);
    }

    public function testCreateWithExistingUuidIsIdempotentAndDoesNotTouchTheClient(): void
    {
        $existing = $this->payment(amount: 100.0);

        $this->repository->method('findByUuidAndNotary')->willReturn($existing);
        $this->clientRepository->expects($this->never())->method('findByIdAndNotary');
        $this->repository->expects($this->never())->method('insert');
        $this->repository->expects($this->never())->method('update');

        // The body differs from the stored payment: it is ignored
        $result = $this->service->create($this->createDto(['amount' => 9999]), self::NOTARY);

        $this->assertFalse($result->created);
        $this->assertSame($existing, $result->payment);
    }

    public function testCreateWithUnknownClientThrowsNotFound(): void
    {
        $this->repository->method('findByUuidAndNotary')->willReturn(null);
        $this->clientRepository->method('findByIdAndNotary')->willReturn(null);
        $this->repository->expects($this->never())->method('insert');

        try {
            $this->service->create($this->createDto(), self::NOTARY);
            $this->fail('PaymentException expected');
        } catch (PaymentException $e) {
            $this->assertSame(PaymentError::ClientNotFound, $e->error);
        }
    }

    public function testCreateWithInactiveClientThrowsUnprocessable(): void
    {
        $this->repository->method('findByUuidAndNotary')->willReturn(null);
        $this->givenClient(self::INACTIVE);
        $this->repository->expects($this->never())->method('insert');

        try {
            $this->service->create($this->createDto(), self::NOTARY);
            $this->fail('PaymentException expected');
        } catch (PaymentException $e) {
            $this->assertSame(PaymentError::ClientInactive, $e->error);
        }
    }

    public function testCreateLooksTheClientUpInTheRequestNotary(): void
    {
        $this->repository->method('findByUuidAndNotary')->willReturn(null);
        $this->clientRepository->expects($this->once())->method('findByIdAndNotary')
            ->with(self::CLIENT, 2)->willReturn(null);

        $this->expectException(PaymentException::class);
        $this->service->create($this->createDto(), 2);
    }

    public function testCreateLosingTheRaceReturnsTheWinnerAsExisting(): void
    {
        $winner = $this->payment();

        $this->repository->expects($this->exactly(2))->method('findByUuidAndNotary')
            ->willReturnOnConsecutiveCalls(null, $winner);
        $this->givenClient();
        $this->repository->method('insert')->willThrowException(new DuplicatePaymentUuidException());

        $result = $this->service->create($this->createDto(), self::NOTARY);

        $this->assertFalse($result->created);
        $this->assertSame($winner, $result->payment);
    }

    public function testCreateFailsLoudlyWhenTheDuplicateCannotBeReloaded(): void
    {
        $this->repository->method('findByUuidAndNotary')->willReturn(null);
        $this->givenClient();
        $this->repository->method('insert')->willThrowException(new DuplicatePaymentUuidException());

        $this->expectException(\LogicException::class);
        $this->service->create($this->createDto(), self::NOTARY);
    }

    // ---- update ----------------------------------------------------------

    public function testUpdateMergesOmittedFieldsWithTheStoredOnes(): void
    {
        $current = $this->payment(date: '2024-04-02', comments: 'Adelanto');
        $updated = $this->payment(amount: 5500.0);

        $this->repository->expects($this->exactly(2))->method('findByIdAndNotary')
            ->willReturnOnConsecutiveCalls($current, $updated);
        $this->repository->expects($this->once())->method('update')
            ->with(self::ID, self::NOTARY, 5500.0, '2024-04-02', 'Adelanto');

        $this->assertSame($updated, $this->service->update(self::ID, $this->updateDto(), self::NOTARY));
    }

    public function testUpdateNullFieldsKeepTheStoredValues(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn($this->payment());
        $this->repository->expects($this->once())->method('update')
            ->with(self::ID, self::NOTARY, 5500.0, '2024-04-02', 'Adelanto');

        $this->service->update(
            self::ID,
            $this->updateDto(['paymentDate' => null, 'comments' => null]),
            self::NOTARY
        );
    }

    public function testUpdateOverwritesProvidedFieldsAndEmptyCommentsClearThem(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn($this->payment());
        $this->repository->expects($this->once())->method('update')
            ->with(self::ID, self::NOTARY, 5500.0, '2025-01-31', '');

        $this->service->update(
            self::ID,
            $this->updateDto(['paymentDate' => '2025-01-31', 'comments' => '']),
            self::NOTARY
        );
    }

    public function testUpdateKeepsANullLegacyDate(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn($this->payment(date: null));
        $this->repository->expects($this->once())->method('update')
            ->with(self::ID, self::NOTARY, 5500.0, null, 'Adelanto');

        $this->service->update(self::ID, $this->updateDto(), self::NOTARY);
    }

    public function testUpdateOfMissingPaymentThrowsNotFound(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(null);
        $this->repository->expects($this->never())->method('update');

        try {
            $this->service->update(self::ID, $this->updateDto(), 2);
            $this->fail('PaymentException expected');
        } catch (PaymentException $e) {
            $this->assertSame(PaymentError::PaymentNotFound, $e->error);
        }
    }

    public function testUpdateRejectsADifferentClient(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn($this->payment());
        $this->repository->expects($this->never())->method('update');

        try {
            $this->service->update(self::ID, $this->updateDto(['clientId' => 7]), self::NOTARY);
            $this->fail('PaymentException expected');
        } catch (PaymentException $e) {
            $this->assertSame(PaymentError::CannotChangeClient, $e->error);
        }
    }

    public function testUpdateRejectsADifferentUuid(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn($this->payment());
        $this->repository->expects($this->never())->method('update');

        try {
            $this->service->update(self::ID, $this->updateDto(['uuid' => 'xyz789']), self::NOTARY);
            $this->fail('PaymentException expected');
        } catch (PaymentException $e) {
            $this->assertSame(PaymentError::CannotChangeUuid, $e->error);
        }
    }

    public function testUpdateUuidComparisonIsCaseSensitive(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn($this->payment(uuid: 'abc'));
        $this->repository->expects($this->never())->method('update');

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Cannot change UUID');
        $this->service->update(self::ID, $this->updateDto(['uuid' => 'ABC']), self::NOTARY);
    }

    public function testUpdateAcceptsTheSameUuidAndClientResent(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn($this->payment());
        $this->repository->expects($this->once())->method('update');

        $payment = $this->service->update(
            self::ID,
            $this->updateDto(['uuid' => self::UUID, 'clientId' => self::CLIENT]),
            self::NOTARY
        );

        $this->assertSame(self::ID, $payment->id);
    }

    public function testUpdateDoesNotRequireAnActiveClient(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn($this->payment());
        $this->clientRepository->expects($this->never())->method('findByIdAndNotary');
        $this->repository->expects($this->once())->method('update');

        $this->service->update(self::ID, $this->updateDto(), self::NOTARY);
    }

    public function testDeleteRemovesPayment(): void
    {
        $this->repository->expects($this->once())
            ->method('delete')->with(self::ID, self::NOTARY)->willReturn(true);
        // No pre-lookup (ADR-001) and no client-status restriction (scenario 8)
        $this->repository->expects($this->never())->method('findByIdAndNotary');
        $this->clientRepository->expects($this->never())->method('findByIdAndNotary');

        $this->service->delete(self::ID, self::NOTARY);
    }

    public function testDeleteThrowsNotFoundWhenNothingDeleted(): void
    {
        $this->repository->expects($this->once())
            ->method('delete')->with(self::ID, self::NOTARY)->willReturn(false);

        try {
            $this->service->delete(self::ID, self::NOTARY);
            $this->fail('Expected PaymentException');
        } catch (PaymentException $e) {
            $this->assertSame(PaymentError::PaymentNotFound, $e->error);
        }
    }
}
