<?php

declare(strict_types=1);

namespace Tests\Module\PaymentProcedureTax\Service;

use App\Module\PaymentProcedure\Entity\PaymentProcedure;
use App\Module\PaymentProcedure\Repository\PaymentProcedureRepository;
use App\Module\PaymentProcedureTax\DTO\ProcedureTaxItem;
use App\Module\PaymentProcedureTax\Entity\PaymentProcedureTax;
use App\Module\PaymentProcedureTax\Exception\InvalidProcedureTaxItemsException;
use App\Module\PaymentProcedureTax\Exception\ProcedureTaxError;
use App\Module\PaymentProcedureTax\Exception\ProcedureTaxException;
use App\Module\PaymentProcedureTax\Repository\PaymentProcedureTaxRepository;
use App\Module\PaymentProcedureTax\Service\PaymentProcedureTaxService;
use App\Module\PaymentTax\Entity\PaymentTax;
use App\Module\PaymentTax\Repository\PaymentTaxRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PaymentProcedureTaxServiceTest extends TestCase
{
    private const NOTARY = 1;
    private const PROCEDURE = 10;

    private PaymentProcedureTaxService $service;
    private PaymentProcedureTaxRepository&MockObject $repository;
    private PaymentProcedureRepository&MockObject $procedureRepository;
    private PaymentTaxRepository&MockObject $taxRepository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PaymentProcedureTaxRepository::class);
        $this->procedureRepository = $this->createMock(PaymentProcedureRepository::class);
        $this->taxRepository = $this->createMock(PaymentTaxRepository::class);

        $this->service = new PaymentProcedureTaxService(
            $this->repository,
            $this->procedureRepository,
            $this->taxRepository
        );
    }

    private function givenProcedure(int $status = 1): void
    {
        $this->procedureRepository->method('findByIdAndNotary')
            ->with(self::PROCEDURE, self::NOTARY)
            ->willReturn(new PaymentProcedure(self::PROCEDURE, self::NOTARY, 'Escritura', $status));
    }

    private function givenNoProcedure(): void
    {
        $this->procedureRepository->method('findByIdAndNotary')->willReturn(null);
    }

    /** @param array<int, int> $statusById tax id => catalog status */
    private function givenTaxes(array $statusById): void
    {
        $taxes = [];
        foreach ($statusById as $id => $status) {
            $taxes[$id] = new PaymentTax($id, self::NOTARY, "Tax $id", $status);
        }
        $this->taxRepository->method('findByIdsAndNotary')->willReturn($taxes);
        $this->taxRepository->method('findByIdAndNotary')
            ->willReturnCallback(fn (int $id) => $taxes[$id] ?? null);
    }

    private function assertThrowsError(ProcedureTaxError $expected, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected ProcedureTaxException');
        } catch (ProcedureTaxException $e) {
            $this->assertSame($expected, $e->error);
        }
    }

    // ---- getByProcedure -------------------------------------------------

    public function testGetByProcedureReturnsActiveTaxes(): void
    {
        $this->givenProcedure();
        $taxes = [new PaymentProcedureTax(self::PROCEDURE, 1, 'ISR', 5.0, 1)];
        $this->repository->expects($this->once())->method('findActiveByProcedure')
            ->with(self::PROCEDURE, self::NOTARY)->willReturn($taxes);

        $this->assertSame($taxes, $this->service->getByProcedure(self::PROCEDURE, self::NOTARY));
    }

    public function testGetByProcedureAllowsInactiveProcedure(): void
    {
        $this->givenProcedure(2);
        $this->repository->method('findActiveByProcedure')->willReturn([]);

        $this->assertSame([], $this->service->getByProcedure(self::PROCEDURE, self::NOTARY));
    }

    public function testGetByProcedureFailsForForeignProcedure(): void
    {
        $this->givenNoProcedure();
        $this->repository->expects($this->never())->method('findActiveByProcedure');

        $this->assertThrowsError(
            ProcedureTaxError::ProcedureNotFound,
            fn () => $this->service->getByProcedure(self::PROCEDURE, self::NOTARY)
        );
    }

    // ---- add ------------------------------------------------------------

    public function testAddFailsForForeignProcedure(): void
    {
        $this->givenNoProcedure();
        $this->repository->expects($this->never())->method('upsertMany');

        $this->assertThrowsError(
            ProcedureTaxError::ProcedureNotFound,
            fn () => $this->service->add(self::PROCEDURE, [new ProcedureTaxItem(1, 0.0)], self::NOTARY)
        );
    }

    public function testAddFailsForInactiveProcedure(): void
    {
        $this->givenProcedure(2);
        $this->repository->expects($this->never())->method('upsertMany');

        $this->assertThrowsError(
            ProcedureTaxError::ProcedureInactive,
            fn () => $this->service->add(self::PROCEDURE, [new ProcedureTaxItem(1, 0.0)], self::NOTARY)
        );
    }

    public function testAddWritesNewItems(): void
    {
        $this->givenProcedure();
        $this->givenTaxes([1 => 1, 2 => 1]);
        $this->repository->method('findStatuses')->willReturn([]);
        $items = [new ProcedureTaxItem(1, 10.0), new ProcedureTaxItem(2, 0.0)];

        $this->repository->expects($this->once())->method('upsertMany')->with(self::PROCEDURE, $items);

        $this->service->add(self::PROCEDURE, $items, self::NOTARY);
    }

    public function testAddReactivatesInactiveAssignment(): void
    {
        $this->givenProcedure();
        $this->givenTaxes([1 => 1]);
        $this->repository->method('findStatuses')->willReturn([1 => PaymentProcedureTax::STATUS_INACTIVE]);
        $items = [new ProcedureTaxItem(1, 25.0)];

        $this->repository->expects($this->once())->method('upsertMany')->with(self::PROCEDURE, $items);

        $this->service->add(self::PROCEDURE, $items, self::NOTARY);
    }

    public function testAddReportsAllItemErrorsAndNeverWrites(): void
    {
        $this->givenProcedure();
        // 1 ok, 2 inactive in catalog, 3 missing (other notary), 4 already assigned
        $this->givenTaxes([1 => 1, 2 => 2, 4 => 1]);
        $this->repository->method('findStatuses')->willReturn([4 => PaymentProcedureTax::STATUS_ACTIVE]);
        $this->repository->expects($this->never())->method('upsertMany');

        $items = [
            new ProcedureTaxItem(1, 0.0),
            new ProcedureTaxItem(2, 0.0),
            new ProcedureTaxItem(3, 0.0),
            new ProcedureTaxItem(4, 0.0),
        ];

        try {
            $this->service->add(self::PROCEDURE, $items, self::NOTARY);
            $this->fail('Expected InvalidProcedureTaxItemsException');
        } catch (InvalidProcedureTaxItemsException $e) {
            $this->assertSame([
                1 => ProcedureTaxError::TaxInactive,
                2 => ProcedureTaxError::TaxNotFound,
                3 => ProcedureTaxError::AlreadyAssigned,
            ], $e->itemErrors);
        }
    }

    public function testAddQueriesOncePerRepositoryRegardlessOfBulkSize(): void
    {
        $this->givenProcedure();
        $this->givenTaxes([1 => 1, 2 => 1, 3 => 1]);
        $this->repository->expects($this->once())->method('findStatuses')->willReturn([]);
        $this->repository->expects($this->once())->method('upsertMany');

        $this->service->add(
            self::PROCEDURE,
            [new ProcedureTaxItem(1, 0.0), new ProcedureTaxItem(2, 0.0), new ProcedureTaxItem(3, 0.0)],
            self::NOTARY
        );
    }

    // ---- updateDefaultValue ---------------------------------------------

    public function testUpdateFailsForForeignProcedure(): void
    {
        $this->givenNoProcedure();
        $this->repository->expects($this->never())->method('updateDefaultValue');

        $this->assertThrowsError(
            ProcedureTaxError::ProcedureNotFound,
            fn () => $this->service->updateDefaultValue(self::PROCEDURE, 1, 5.0, self::NOTARY)
        );
    }

    public function testUpdateFailsForInactiveProcedure(): void
    {
        $this->givenProcedure(2);

        $this->assertThrowsError(
            ProcedureTaxError::ProcedureInactive,
            fn () => $this->service->updateDefaultValue(self::PROCEDURE, 1, 5.0, self::NOTARY)
        );
    }

    public function testUpdateFailsWhenTaxMissing(): void
    {
        $this->givenProcedure();
        $this->givenTaxes([]);

        $this->assertThrowsError(
            ProcedureTaxError::TaxNotFound,
            fn () => $this->service->updateDefaultValue(self::PROCEDURE, 1, 5.0, self::NOTARY)
        );
    }

    public function testUpdateFailsWhenTaxInactive(): void
    {
        $this->givenProcedure();
        $this->givenTaxes([1 => 2]);

        $this->assertThrowsError(
            ProcedureTaxError::TaxInactive,
            fn () => $this->service->updateDefaultValue(self::PROCEDURE, 1, 5.0, self::NOTARY)
        );
    }

    public function testUpdateFailsWithoutActiveAssignment(): void
    {
        $this->givenProcedure();
        $this->givenTaxes([1 => 1]);
        $this->repository->method('findStatuses')->willReturn([1 => PaymentProcedureTax::STATUS_INACTIVE]);
        $this->repository->expects($this->never())->method('updateDefaultValue');

        $this->assertThrowsError(
            ProcedureTaxError::NotAssigned,
            fn () => $this->service->updateDefaultValue(self::PROCEDURE, 1, 5.0, self::NOTARY)
        );
    }

    public function testUpdateFailsWhenNeverAssigned(): void
    {
        $this->givenProcedure();
        $this->givenTaxes([1 => 1]);
        $this->repository->method('findStatuses')->willReturn([]);

        $this->assertThrowsError(
            ProcedureTaxError::NotAssigned,
            fn () => $this->service->updateDefaultValue(self::PROCEDURE, 1, 5.0, self::NOTARY)
        );
    }

    public function testUpdateWritesValue(): void
    {
        $this->givenProcedure();
        $this->givenTaxes([1 => 1]);
        $this->repository->method('findStatuses')->willReturn([1 => PaymentProcedureTax::STATUS_ACTIVE]);
        $this->repository->expects($this->once())->method('updateDefaultValue')
            ->with(self::PROCEDURE, 1, 5.0, self::NOTARY);

        $this->service->updateDefaultValue(self::PROCEDURE, 1, 5.0, self::NOTARY);
    }

    // ---- remove ---------------------------------------------------------

    public function testRemoveFailsForForeignProcedure(): void
    {
        $this->givenNoProcedure();
        $this->repository->expects($this->never())->method('deactivate');

        $this->assertThrowsError(
            ProcedureTaxError::ProcedureNotFound,
            fn () => $this->service->remove(self::PROCEDURE, 1, self::NOTARY)
        );
    }

    public function testRemoveFailsWithoutActiveAssignment(): void
    {
        $this->givenProcedure();
        $this->repository->method('findStatuses')->willReturn([1 => PaymentProcedureTax::STATUS_INACTIVE]);
        $this->repository->expects($this->never())->method('deactivate');

        $this->assertThrowsError(
            ProcedureTaxError::NotAssigned,
            fn () => $this->service->remove(self::PROCEDURE, 1, self::NOTARY)
        );
    }

    public function testRemoveWorksOnInactiveProcedure(): void
    {
        $this->givenProcedure(2);
        $this->repository->method('findStatuses')->willReturn([1 => PaymentProcedureTax::STATUS_ACTIVE]);
        $this->repository->expects($this->once())->method('deactivate')->with(self::PROCEDURE, 1, self::NOTARY);

        $this->service->remove(self::PROCEDURE, 1, self::NOTARY);
    }

    public function testRemoveDoesNotCheckCatalogTaxStatus(): void
    {
        $this->givenProcedure();
        $this->taxRepository->expects($this->never())->method('findByIdAndNotary');
        $this->repository->method('findStatuses')->willReturn([1 => PaymentProcedureTax::STATUS_ACTIVE]);
        $this->repository->expects($this->once())->method('deactivate');

        $this->service->remove(self::PROCEDURE, 1, self::NOTARY);
    }
}
