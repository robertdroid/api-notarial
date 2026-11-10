<?php

declare(strict_types=1);

namespace Tests\Module\PaymentTax\Service;

use App\Module\PaymentTax\DTO\CreatePaymentTaxRequest;
use App\Module\PaymentTax\DTO\UpdatePaymentTaxRequest;
use App\Module\PaymentTax\Entity\PaymentTax;
use App\Module\PaymentTax\Repository\PaymentTaxRepository;
use App\Module\PaymentTax\Service\PaymentTaxService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PaymentTaxServiceTest extends TestCase
{
    private PaymentTaxService $service;
    private PaymentTaxRepository&MockObject $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PaymentTaxRepository::class);
        $this->service = new PaymentTaxService($this->repository);
    }

    public function testGetAllDelegatesFiltersToRepository(): void
    {
        $taxes = [new PaymentTax(1, 1, 'ISR', 1)];

        $this->repository->expects($this->once())
            ->method('findAllByNotary')
            ->with(1, 1, 'isr')
            ->willReturn($taxes);

        $this->assertSame($taxes, $this->service->getAll(1, 1, 'isr'));
    }

    public function testGetByIdReturnsTax(): void
    {
        $tax = new PaymentTax(5, 1, 'ISR', 1);

        $this->repository->method('findByIdAndNotary')->with(5, 1)->willReturn($tax);

        $this->assertSame($tax, $this->service->getById(5, 1));
    }

    public function testGetByIdReturnsNullWhenMissing(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(null);

        $this->assertNull($this->service->getById(99, 1));
    }

    public function testCreatePersistsTax(): void
    {
        $this->repository->method('existsByNameAndNotary')->with('ISR', 1, null)->willReturn(false);
        $this->repository->expects($this->once())->method('create')
            ->with($this->callback(
                fn (PaymentTax $t) => $t->notaryId === 1 && $t->name === 'ISR' && $t->statusId === 1
            ))
            ->willReturn(10);

        $result = $this->service->create(CreatePaymentTaxRequest::fromArray(['taxName' => 'ISR']), 1);

        $this->assertTrue($result);
    }

    public function testCreateReturnsErrorWhenNameIsDuplicate(): void
    {
        $this->repository->method('existsByNameAndNotary')->willReturn(true);
        $this->repository->expects($this->never())->method('create');

        $result = $this->service->create(CreatePaymentTaxRequest::fromArray(['taxName' => 'isr']), 1);

        $this->assertSame('Tax name already exists', $result);
    }

    public function testUpdateReturnsNullWhenTaxDoesNotExist(): void
    {
        $this->repository->method('findByIdAndNotary')->with(99, 1)->willReturn(null);
        $this->repository->expects($this->never())->method('update');

        $result = $this->service->update(99, UpdatePaymentTaxRequest::fromArray(['status' => 2]), 1);

        $this->assertNull($result);
    }

    public function testUpdateOnlyStatusDoesNotCheckNameUniqueness(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(new PaymentTax(5, 1, 'ISR', 1));
        $this->repository->expects($this->never())->method('existsByNameAndNotary');
        $this->repository->expects($this->once())->method('update')
            ->with($this->callback(fn (PaymentTax $t) => $t->name === 'ISR' && $t->statusId === 2));

        $result = $this->service->update(5, UpdatePaymentTaxRequest::fromArray(['status' => 2]), 1);

        $this->assertTrue($result);
    }

    public function testUpdateOnlyNameKeepsExistingStatus(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(new PaymentTax(5, 1, 'ISR', 2));
        $this->repository->method('existsByNameAndNotary')->willReturn(false);
        $this->repository->expects($this->once())->method('update')
            ->with($this->callback(fn (PaymentTax $t) => $t->name === 'IVA' && $t->statusId === 2));

        $result = $this->service->update(5, UpdatePaymentTaxRequest::fromArray(['taxName' => 'IVA']), 1);

        $this->assertTrue($result);
    }

    public function testUpdateReturnsErrorWhenNameIsDuplicate(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(new PaymentTax(5, 1, 'IVA', 1));
        $this->repository->method('existsByNameAndNotary')->willReturn(true);
        $this->repository->expects($this->never())->method('update');

        $result = $this->service->update(5, UpdatePaymentTaxRequest::fromArray(['taxName' => 'ISR']), 1);

        $this->assertSame('Tax name already exists', $result);
    }

    public function testUpdateSameNameExcludesOwnId(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(new PaymentTax(5, 1, 'ISR', 1));
        $this->repository->expects($this->once())->method('existsByNameAndNotary')
            ->with('ISR', 1, 5)->willReturn(false);

        $result = $this->service->update(5, UpdatePaymentTaxRequest::fromArray(['taxName' => 'ISR']), 1);

        $this->assertTrue($result);
    }
}
