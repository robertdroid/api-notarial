<?php

declare(strict_types=1);

namespace Tests\Module\PaymentClient\Service;

use App\Module\PaymentClient\DTO\CreatePaymentClientRequest;
use App\Module\PaymentClient\DTO\UpdatePaymentClientRequest;
use App\Module\PaymentClient\Entity\PaymentClient;
use App\Module\PaymentClient\Repository\PaymentClientRepository;
use App\Module\PaymentClient\Service\PaymentClientService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PaymentClientServiceTest extends TestCase
{
    private PaymentClientService $service;
    private PaymentClientRepository&MockObject $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PaymentClientRepository::class);
        $this->service = new PaymentClientService($this->repository);
    }

    public function testGetAllDelegatesFiltersToRepository(): void
    {
        $clients = [new PaymentClient(1, 1, 'Juan', '', '', 1)];

        $this->repository->expects($this->once())
            ->method('findAllByNotary')
            ->with(1, 1, 'juan')
            ->willReturn($clients);

        $this->assertSame($clients, $this->service->getAll(1, 1, 'juan'));
    }

    public function testGetByIdReturnsClient(): void
    {
        $client = new PaymentClient(5, 1, 'Juan', '', '', 1);

        $this->repository->expects($this->once())
            ->method('findByIdAndNotary')
            ->with(5, 1)
            ->willReturn($client);

        $this->assertSame($client, $this->service->getById(5, 1));
    }

    public function testGetByIdReturnsNullWhenMissing(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(null);

        $this->assertNull($this->service->getById(99, 1));
    }

    public function testCreatePassesNotaryIdFromTokenAndDoesNotCheckUniqueness(): void
    {
        $dto = CreatePaymentClientRequest::fromArray([
            'fullName' => 'Juan',
            'phone' => '555',
            'email' => 'j@x.com',
            'notaryId' => 99,
        ]);

        $this->repository->expects($this->once())
            ->method('create')
            ->with($this->callback(fn (PaymentClient $c) =>
                $c->id === 0
                && $c->notaryId === 3
                && $c->fullName === 'Juan'
                && $c->phone === '555'
                && $c->email === 'j@x.com'
                && $c->statusId === 1))
            ->willReturn(10);

        $this->service->create($dto, 3);
    }

    public function testUpdateKeepsAbsentFieldsAndClearsEmptyOnes(): void
    {
        $existing = new PaymentClient(5, 1, 'Juan', '555', 'juan@x.com', 1);
        $this->repository->method('findByIdAndNotary')->willReturn($existing);

        $this->repository->expects($this->once())->method('update')
            ->with($this->callback(fn (PaymentClient $c) =>
                $c->id === 5
                && $c->fullName === 'Juan'
                && $c->phone === '555'
                && $c->email === ''
                && $c->statusId === 1
                && $c->notaryId === 1));

        $this->assertTrue(
            $this->service->update(5, UpdatePaymentClientRequest::fromArray(['email' => '']), 1)
        );
    }

    public function testUpdateOverridesProvidedFields(): void
    {
        $existing = new PaymentClient(5, 1, 'Juan', '555', 'juan@x.com', 1);
        $this->repository->method('findByIdAndNotary')->willReturn($existing);

        $this->repository->expects($this->once())->method('update')
            ->with($this->callback(fn (PaymentClient $c) =>
                $c->fullName === 'Ana' && $c->phone === '777' && $c->email === 'juan@x.com' && $c->statusId === 2));

        $this->assertTrue($this->service->update(
            5,
            UpdatePaymentClientRequest::fromArray(['fullName' => 'Ana', 'phone' => '777', 'status' => 2]),
            1
        ));
    }

    public function testUpdateOfMissingClientReturnsFalseWithoutWriting(): void
    {
        $this->repository->expects($this->once())
            ->method('findByIdAndNotary')
            ->with(999, 1)
            ->willReturn(null);
        $this->repository->expects($this->never())->method('update');

        $this->assertFalse(
            $this->service->update(999, UpdatePaymentClientRequest::fromArray(['status' => 2]), 1)
        );
    }
}
