<?php

declare(strict_types=1);

namespace Tests\Module\PaymentProcedure\Service;

use App\Module\PaymentProcedure\DTO\CreatePaymentProcedureRequest;
use App\Module\PaymentProcedure\DTO\UpdatePaymentProcedureRequest;
use App\Module\PaymentProcedure\Entity\PaymentProcedure;
use App\Module\PaymentProcedure\Repository\PaymentProcedureRepository;
use App\Module\PaymentProcedure\Service\PaymentProcedureService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PaymentProcedureServiceTest extends TestCase
{
    private PaymentProcedureService $service;
    private PaymentProcedureRepository&MockObject $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PaymentProcedureRepository::class);
        $this->service = new PaymentProcedureService($this->repository);
    }

    public function testGetAllDelegatesFiltersToRepository(): void
    {
        $procedures = [new PaymentProcedure(1, 1, 'Escritura', 1)];

        $this->repository->expects($this->once())
            ->method('findAllByNotary')
            ->with(1, 1, 'escritura')
            ->willReturn($procedures);

        $this->assertSame($procedures, $this->service->getAll(1, 1, 'escritura'));
    }

    public function testGetByIdReturnsProcedure(): void
    {
        $procedure = new PaymentProcedure(5, 1, 'Escritura', 1);

        $this->repository->method('findByIdAndNotary')->with(5, 1)->willReturn($procedure);

        $this->assertSame($procedure, $this->service->getById(5, 1));
    }

    public function testGetByIdReturnsNullWhenMissing(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(null);

        $this->assertNull($this->service->getById(99, 1));
    }

    public function testCreatePersistsProcedure(): void
    {
        $this->repository->method('existsByNameAndNotary')->with('Escritura', 1, null)->willReturn(false);
        $this->repository->expects($this->once())->method('create')
            ->with($this->callback(
                fn (PaymentProcedure $p) => $p->notaryId === 1 && $p->name === 'Escritura' && $p->statusId === 1
            ))
            ->willReturn(10);

        $result = $this->service->create(CreatePaymentProcedureRequest::fromArray(['procedureName' => 'Escritura']), 1);

        $this->assertTrue($result);
    }

    public function testCreateReturnsErrorWhenNameIsDuplicate(): void
    {
        $this->repository->method('existsByNameAndNotary')->willReturn(true);
        $this->repository->expects($this->never())->method('create');

        $result = $this->service->create(CreatePaymentProcedureRequest::fromArray(['procedureName' => 'escritura']), 1);

        $this->assertSame('Procedure name already exists', $result);
    }

    public function testUpdateReturnsNullWhenProcedureDoesNotExist(): void
    {
        $this->repository->method('findByIdAndNotary')->with(99, 1)->willReturn(null);
        $this->repository->expects($this->never())->method('update');

        $result = $this->service->update(99, UpdatePaymentProcedureRequest::fromArray(['status' => 2]), 1);

        $this->assertNull($result);
    }

    public function testUpdateOnlyStatusDoesNotCheckNameUniqueness(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(new PaymentProcedure(5, 1, 'Escritura', 1));
        $this->repository->expects($this->never())->method('existsByNameAndNotary');
        $this->repository->expects($this->once())->method('update')
            ->with($this->callback(fn (PaymentProcedure $p) => $p->name === 'Escritura' && $p->statusId === 2));

        $result = $this->service->update(5, UpdatePaymentProcedureRequest::fromArray(['status' => 2]), 1);

        $this->assertTrue($result);
    }

    public function testUpdateOnlyNameKeepsExistingStatus(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(new PaymentProcedure(5, 1, 'Escritura', 2));
        $this->repository->method('existsByNameAndNotary')->willReturn(false);
        $this->repository->expects($this->once())->method('update')
            ->with($this->callback(fn (PaymentProcedure $p) => $p->name === 'Certificacion' && $p->statusId === 2));

        $result = $this->service->update(5, UpdatePaymentProcedureRequest::fromArray(['procedureName' => 'Certificacion']), 1);

        $this->assertTrue($result);
    }

    public function testUpdateReturnsErrorWhenNameIsDuplicate(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(new PaymentProcedure(5, 1, 'Certificacion', 1));
        $this->repository->method('existsByNameAndNotary')->willReturn(true);
        $this->repository->expects($this->never())->method('update');

        $result = $this->service->update(5, UpdatePaymentProcedureRequest::fromArray(['procedureName' => 'Escritura']), 1);

        $this->assertSame('Procedure name already exists', $result);
    }

    public function testUpdateWithSameNameDoesNotValidateUniqueness(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(new PaymentProcedure(5, 1, 'Escritura', 1));
        $this->repository->expects($this->never())->method('existsByNameAndNotary');
        $this->repository->expects($this->once())->method('update');

        $result = $this->service->update(5, UpdatePaymentProcedureRequest::fromArray(['procedureName' => 'Escritura']), 1);

        $this->assertTrue($result);
    }

    public function testUpdateWithSameNameInDifferentCaseDoesNotValidateUniqueness(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(new PaymentProcedure(12, 1, 'RATIFICACION', 1));
        $this->repository->expects($this->never())->method('existsByNameAndNotary');
        $this->repository->expects($this->once())->method('update');

        $result = $this->service->update(12, UpdatePaymentProcedureRequest::fromArray(['procedureName' => 'Ratificacion']), 1);

        $this->assertTrue($result);
    }

    public function testUpdateWithChangedNameExcludesOwnIdInUniquenessCheck(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(new PaymentProcedure(5, 1, 'Escritura', 1));
        $this->repository->expects($this->once())->method('existsByNameAndNotary')
            ->with('Certificacion', 1, 5)->willReturn(false);

        $result = $this->service->update(5, UpdatePaymentProcedureRequest::fromArray(['procedureName' => 'Certificacion']), 1);

        $this->assertTrue($result);
    }
}
