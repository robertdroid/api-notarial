<?php

declare(strict_types=1);

namespace Tests\Module\PaymentTransaction\Service;

use App\Core\Database\TransactionManager;
use App\Module\PaymentClient\Entity\PaymentClient;
use App\Module\PaymentClient\Repository\PaymentClientRepository;
use App\Module\PaymentProcedure\Entity\PaymentProcedure;
use App\Module\PaymentProcedure\Repository\PaymentProcedureRepository;
use App\Module\PaymentProcedureTax\Entity\PaymentProcedureTax;
use App\Module\PaymentProcedureTax\Repository\PaymentProcedureTaxRepository;
use App\Module\PaymentTax\Entity\PaymentTax;
use App\Module\PaymentTax\Repository\PaymentTaxRepository;
use App\Module\PaymentTransaction\DTO\CreateTransactionRequest;
use App\Module\PaymentTransaction\DTO\TransactionDetailItem;
use App\Module\PaymentTransaction\DTO\UpdateTransactionRequest;
use App\Module\PaymentTransaction\Entity\PaymentTransaction;
use App\Module\PaymentTransaction\Entity\PaymentTransactionDetail;
use App\Module\PaymentTransaction\Exception\DuplicateTransactionUuidException;
use App\Module\PaymentTransaction\Exception\TransactionError;
use App\Module\PaymentTransaction\Exception\TransactionException;
use App\Module\PaymentTransaction\Repository\PaymentTransactionRepository;
use App\Module\PaymentTransaction\Service\PaymentTransactionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PaymentTransactionServiceTest extends TestCase
{
    private const NOTARY = 1;
    private const CLIENT = 5;
    private const PROCEDURE = 10;
    private const UUID = 'AbC123';

    private PaymentTransactionService $service;
    private PaymentTransactionRepository&MockObject $repository;
    private PaymentClientRepository&MockObject $clientRepository;
    private PaymentProcedureRepository&MockObject $procedureRepository;
    private PaymentTaxRepository&MockObject $taxRepository;
    private PaymentProcedureTaxRepository&MockObject $procedureTaxRepository;
    private TransactionManager&MockObject $transactions;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PaymentTransactionRepository::class);
        $this->clientRepository = $this->createMock(PaymentClientRepository::class);
        $this->procedureRepository = $this->createMock(PaymentProcedureRepository::class);
        $this->taxRepository = $this->createMock(PaymentTaxRepository::class);
        $this->procedureTaxRepository = $this->createMock(PaymentProcedureTaxRepository::class);
        $this->transactions = $this->createMock(TransactionManager::class);
        $this->transactions->method('transactional')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation());

        $this->service = new PaymentTransactionService(
            $this->repository,
            $this->clientRepository,
            $this->procedureRepository,
            $this->taxRepository,
            $this->procedureTaxRepository,
            $this->transactions
        );
    }

    // ---- helpers ---------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function createDto(array $overrides = []): CreateTransactionRequest
    {
        return CreateTransactionRequest::fromArray($overrides + [
            'clientId' => self::CLIENT, 'procedureId' => self::PROCEDURE, 'uuid' => self::UUID,
            'date' => '2026-11-11', 'fee' => 3000,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function updateDto(array $overrides = []): UpdateTransactionRequest
    {
        return UpdateTransactionRequest::fromArray($overrides + [
            'procedureId' => self::PROCEDURE, 'date' => '2026-11-11', 'fee' => 3000,
        ]);
    }

    private function givenClient(int $status = 1): void
    {
        $this->clientRepository->method('findByIdAndNotary')
            ->willReturn(new PaymentClient(self::CLIENT, self::NOTARY, 'Ana', '', '', $status));
    }

    private function givenProcedure(int $status = 1, int $id = self::PROCEDURE): void
    {
        $this->procedureRepository->method('findByIdAndNotary')
            ->willReturn(new PaymentProcedure($id, self::NOTARY, 'Poder', $status));
    }

    private function givenManualMode(): void
    {
        $this->procedureTaxRepository->method('findActiveByProcedure')->willReturn([]);
    }

    private function givenCalculatedMode(): void
    {
        $this->procedureTaxRepository->method('findActiveByProcedure')
            ->willReturn([new PaymentProcedureTax(self::PROCEDURE, 1, 'Tax 1', 0.0, PaymentProcedureTax::STATUS_ACTIVE)]);
    }

    /** @param array<int, int> $statusById tax id => status; ids not listed do not exist in the notary */
    private function givenTaxCatalog(array $statusById): void
    {
        $catalog = [];
        foreach ($statusById as $id => $status) {
            $catalog[$id] = new PaymentTax($id, self::NOTARY, "Tax $id", $status);
        }
        $this->taxRepository->method('findByIdsAndNotary')->willReturn($catalog);
    }

    /** @param PaymentTransactionDetail[] $details */
    private function storedTransaction(
        int $id = 7,
        int $procedureId = self::PROCEDURE,
        array $details = [],
        string $uuid = self::UUID,
    ): PaymentTransaction {
        return new PaymentTransaction(
            $id, $uuid, self::NOTARY, self::CLIENT, 'Ana', $procedureId, 'Poder', '2026-11-11',
            3000.0, 0.0, 3000.0, '', '', '', 1, $details
        );
    }

    private function assertThrowsError(TransactionError $expected, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame($expected, $e->error);
        }
    }

    /** Captures the taxes and total passed to insert(), and the details passed to insertDetails(). */
    private function captureCreate(): \stdClass
    {
        $captured = new \stdClass();
        $captured->taxes = null;
        $captured->total = null;
        $captured->details = null;

        $this->repository->method('insert')->willReturnCallback(
            function ($dto, $notaryId, $taxes, $total) use ($captured): int {
                $captured->taxes = $taxes;
                $captured->total = $total;

                return 7;
            }
        );
        $this->repository->method('insertDetails')->willReturnCallback(
            function (int $id, array $items) use ($captured): void {
                $captured->details = $items;
            }
        );
        $this->repository->method('findByIdAndNotary')->willReturn($this->storedTransaction());

        return $captured;
    }

    /** @param TransactionDetailItem[] $items @return int[] */
    private static function taxIds(array $items): array
    {
        return array_map(static fn (TransactionDetailItem $i): int => $i->taxId, $items);
    }

    // ---- getById / getByUuid --------------------------------------------

    public function testGetByIdReturnsTheTransaction(): void
    {
        $tx = $this->storedTransaction();
        $this->repository->method('findByIdAndNotary')->with(7, self::NOTARY)->willReturn($tx);

        $this->assertSame($tx, $this->service->getById(7, self::NOTARY));
    }

    public function testGetByIdThrowsWhenMissing(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(null);

        $this->assertThrowsError(TransactionError::TransactionNotFound, fn () => $this->service->getById(7, self::NOTARY));
    }

    public function testGetByUuidReturnsTheTransaction(): void
    {
        $tx = $this->storedTransaction();
        $this->repository->method('findByUuidAndNotary')->with(self::UUID, self::NOTARY)->willReturn($tx);

        $this->assertSame($tx, $this->service->getByUuid(self::UUID, self::NOTARY));
    }

    public function testGetByUuidThrowsWhenMissing(): void
    {
        $this->repository->method('findByUuidAndNotary')->willReturn(null);

        $this->assertThrowsError(TransactionError::TransactionNotFound, fn () => $this->service->getByUuid('x', self::NOTARY));
    }

    // ---- create: idempotency and validations ------------------------------

    public function testCreateWithExistingUuidReturnsItWithoutWritingOrValidatingCatalogs(): void
    {
        $existing = $this->storedTransaction();
        $this->repository->method('findByUuidAndNotary')->with(self::UUID, self::NOTARY)->willReturn($existing);
        $this->repository->expects($this->never())->method('insert');
        $this->repository->expects($this->never())->method('insertDetails');
        $this->clientRepository->expects($this->never())->method('findByIdAndNotary');
        $this->procedureRepository->expects($this->never())->method('findByIdAndNotary');
        $this->transactions->expects($this->never())->method('transactional');

        $result = $this->service->create($this->createDto(), self::NOTARY);

        $this->assertFalse($result->created);
        $this->assertSame($existing, $result->transaction);
    }

    public function testCreateThrowsClientNotFound(): void
    {
        $this->clientRepository->method('findByIdAndNotary')->willReturn(null);
        $this->repository->expects($this->never())->method('insert');

        $this->assertThrowsError(TransactionError::ClientNotFound, fn () => $this->service->create($this->createDto(), self::NOTARY));
    }

    public function testCreateThrowsClientInactive(): void
    {
        $this->givenClient(2);
        $this->repository->expects($this->never())->method('insert');

        $this->assertThrowsError(TransactionError::ClientInactive, fn () => $this->service->create($this->createDto(), self::NOTARY));
    }

    public function testCreateThrowsProcedureNotFound(): void
    {
        $this->givenClient();
        $this->procedureRepository->method('findByIdAndNotary')->willReturn(null);
        $this->repository->expects($this->never())->method('insert');

        $this->assertThrowsError(TransactionError::ProcedureNotFound, fn () => $this->service->create($this->createDto(), self::NOTARY));
    }

    public function testCreateThrowsProcedureInactive(): void
    {
        $this->givenClient();
        $this->givenProcedure(2);
        $this->repository->expects($this->never())->method('insert');

        $this->assertThrowsError(TransactionError::ProcedureInactive, fn () => $this->service->create($this->createDto(), self::NOTARY));
    }

    // ---- create: calculation modes -----------------------------------------

    public function testCreateCalculatedModeSumsDetailsAndIgnoresRequestTaxes(): void
    {
        $this->givenClient();
        $this->givenProcedure();
        $this->givenCalculatedMode();
        $this->givenTaxCatalog([1 => 1, 2 => 1]);
        $captured = $this->captureCreate();

        $result = $this->service->create($this->createDto([
            'taxes' => 999,
            'details' => [['taxId' => 1, 'quantity' => 1, 'amount' => 500], ['taxId' => 2, 'quantity' => 2, 'amount' => 250]],
        ]), self::NOTARY);

        $this->assertTrue($result->created);
        $this->assertSame(1000.0, $captured->taxes);
        $this->assertSame(4000.0, $captured->total);
        $this->assertSame([1, 2], self::taxIds($captured->details));
    }

    public function testCreateCalculatedModeWithoutDetailsHasZeroTaxes(): void
    {
        $this->givenClient();
        $this->givenProcedure();
        $this->givenCalculatedMode();
        $this->givenTaxCatalog([]);
        $captured = $this->captureCreate();

        $this->service->create($this->createDto(), self::NOTARY);

        $this->assertSame(0.0, $captured->taxes);
        $this->assertSame(3000.0, $captured->total);
        $this->assertSame([], $captured->details);
    }

    public function testCreateManualModeUsesRequestTaxes(): void
    {
        $this->givenClient();
        $this->givenProcedure();
        $this->givenManualMode();
        $this->taxRepository->expects($this->never())->method('findByIdsAndNotary');
        $captured = $this->captureCreate();

        $this->service->create($this->createDto(['taxes' => 1430]), self::NOTARY);

        $this->assertSame(1430.0, $captured->taxes);
        $this->assertSame(4430.0, $captured->total);
        $this->assertSame([], $captured->details);
    }

    public function testCreateManualModeSilentlyIgnoresDetails(): void
    {
        $this->givenClient();
        $this->givenProcedure();
        $this->givenManualMode();
        $captured = $this->captureCreate();

        $this->service->create($this->createDto([
            'taxes' => 200, 'details' => [['taxId' => 1, 'quantity' => 1, 'amount' => 500]],
        ]), self::NOTARY);

        $this->assertSame(200.0, $captured->taxes);
        $this->assertSame(3200.0, $captured->total);
        $this->assertSame([], $captured->details);
    }

    public function testCreateFiltersInactiveUnknownAndRepeatedTaxes(): void
    {
        $this->givenClient();
        $this->givenProcedure();
        $this->givenCalculatedMode();
        // 1 active, 2 inactive, 3 does not exist in the notary
        $this->givenTaxCatalog([1 => 1, 2 => 2]);
        $captured = $this->captureCreate();

        $this->service->create($this->createDto([
            'details' => [
                ['taxId' => 1, 'amount' => 100],
                ['taxId' => 1, 'amount' => 900],
                ['taxId' => 2, 'amount' => 50],
                ['taxId' => 3, 'amount' => 70],
            ],
        ]), self::NOTARY);

        $this->assertSame([1], self::taxIds($captured->details));
        $this->assertSame(100.0, $captured->details[0]->amount);
        $this->assertSame(100.0, $captured->taxes);
        $this->assertSame(3100.0, $captured->total);
    }

    public function testCreateLooksUpTaxesWithASingleQuery(): void
    {
        $this->givenClient();
        $this->givenProcedure();
        $this->givenCalculatedMode();
        $this->taxRepository->expects($this->once())->method('findByIdsAndNotary')
            ->with([1, 2, 1], self::NOTARY)->willReturn([]);
        $this->captureCreate();

        $this->service->create($this->createDto([
            'details' => [['taxId' => 1], ['taxId' => 2], ['taxId' => 1]],
        ]), self::NOTARY);
    }

    public function testCreateRoundsToTwoDecimals(): void
    {
        $this->givenClient();
        $this->givenProcedure();
        $this->givenCalculatedMode();
        $this->givenTaxCatalog([1 => 1, 2 => 1]);
        $captured = $this->captureCreate();

        $this->service->create($this->createDto([
            'fee' => 0.1,
            'details' => [['taxId' => 1, 'quantity' => 3, 'amount' => 33.335], ['taxId' => 2, 'amount' => 0.2]],
        ]), self::NOTARY);

        // 3 x 33.34 = 100.02 (amount normalized to cents first) + 0.20
        $this->assertSame(100.22, $captured->taxes);
        $this->assertSame(100.32, $captured->total);
    }

    public function testCreateInsertsHeaderAndDetailsInsideOneTransaction(): void
    {
        $this->givenClient();
        $this->givenProcedure();
        $this->givenManualMode();
        $this->repository->method('findByIdAndNotary')->willReturn($this->storedTransaction());

        $transactions = $this->createMock(TransactionManager::class);
        $transactions->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $service = new PaymentTransactionService(
            $this->repository, $this->clientRepository, $this->procedureRepository,
            $this->taxRepository, $this->procedureTaxRepository, $transactions
        );

        $order = [];
        $this->repository->method('insert')->willReturnCallback(function () use (&$order): int {
            $order[] = 'insert';

            return 7;
        });
        $this->repository->method('insertDetails')->willReturnCallback(function () use (&$order): void {
            $order[] = 'insertDetails';
        });

        $service->create($this->createDto(), self::NOTARY);

        $this->assertSame(['insert', 'insertDetails'], $order);
    }

    public function testCreateReturnsTheWinnerWhenTheInsertLosesTheUuidRace(): void
    {
        $this->givenClient();
        $this->givenProcedure();
        $this->givenManualMode();
        $winner = $this->storedTransaction(3);
        $this->repository->method('findByUuidAndNotary')->willReturnOnConsecutiveCalls(null, $winner);
        $this->repository->method('insert')->willThrowException(new DuplicateTransactionUuidException());
        $this->repository->expects($this->never())->method('insertDetails');

        $result = $this->service->create($this->createDto(), self::NOTARY);

        $this->assertFalse($result->created);
        $this->assertSame($winner, $result->transaction);
    }

    // ---- update ---------------------------------------------------------

    private function givenStored(?PaymentTransaction $stored = null): void
    {
        $this->repository->method('findByIdAndNotary')
            ->with(7, self::NOTARY, $this->anything())
            ->willReturn($stored ?? $this->storedTransaction());
    }

    public function testUpdateReadsTheTransactionWithARowLock(): void
    {
        $this->repository->expects($this->exactly(2))->method('findByIdAndNotary')
            ->willReturnCallback(function (int $id, int $notaryId, bool $forUpdate = false): PaymentTransaction {
                static $calls = 0;
                $this->assertSame($calls === 0, $forUpdate, 'only the first read inside the transaction is locked');
                $calls++;

                return $this->storedTransaction();
            });
        $this->givenManualMode();

        $this->service->update(7, $this->updateDto(), self::NOTARY);
    }

    public function testUpdateThrowsTransactionNotFound(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(null);
        $this->repository->expects($this->never())->method('update');

        $this->assertThrowsError(TransactionError::TransactionNotFound, fn () => $this->service->update(7, $this->updateDto(), self::NOTARY));
    }

    public function testUpdateRejectsADifferentClient(): void
    {
        $this->givenStored();
        $this->repository->expects($this->never())->method('deleteDetails');

        $this->assertThrowsError(
            TransactionError::CannotChangeClient,
            fn () => $this->service->update(7, $this->updateDto(['clientId' => self::CLIENT + 1]), self::NOTARY)
        );
    }

    public function testUpdateRejectsADifferentUuid(): void
    {
        $this->givenStored();
        $this->repository->expects($this->never())->method('deleteDetails');

        $this->assertThrowsError(
            TransactionError::CannotChangeUuid,
            fn () => $this->service->update(7, $this->updateDto(['uuid' => 'other']), self::NOTARY)
        );
    }

    public function testUpdateAcceptsMatchingOrAbsentClientAndUuid(): void
    {
        $this->givenStored();
        $this->givenManualMode();
        $this->repository->expects($this->exactly(2))->method('update');

        $this->service->update(7, $this->updateDto(['clientId' => self::CLIENT, 'uuid' => self::UUID]), self::NOTARY);
        $this->service->update(7, $this->updateDto(), self::NOTARY);
    }

    public function testUpdateDoesNotCheckTheClientStatus(): void
    {
        $this->givenStored();
        $this->givenManualMode();
        $this->clientRepository->expects($this->never())->method('findByIdAndNotary');
        $this->repository->expects($this->once())->method('update');

        $this->service->update(7, $this->updateDto(), self::NOTARY);
    }

    public function testUpdateKeepsAnInactiveProcedureWhenItDoesNotChange(): void
    {
        $this->givenStored();
        $this->givenManualMode();
        $this->procedureRepository->expects($this->never())->method('findByIdAndNotary');
        $this->repository->expects($this->once())->method('update');

        $this->service->update(7, $this->updateDto(), self::NOTARY);
    }

    public function testUpdateThrowsProcedureNotFoundWhenChangingToAMissingOne(): void
    {
        $this->givenStored();
        $this->procedureRepository->method('findByIdAndNotary')->willReturn(null);
        $this->repository->expects($this->never())->method('update');

        $this->assertThrowsError(
            TransactionError::ProcedureNotFound,
            fn () => $this->service->update(7, $this->updateDto(['procedureId' => 99]), self::NOTARY)
        );
    }

    public function testUpdateThrowsProcedureInactiveWhenChangingToAnInactiveOne(): void
    {
        $this->givenStored();
        $this->givenProcedure(2, 99);
        $this->repository->expects($this->never())->method('update');

        $this->assertThrowsError(
            TransactionError::ProcedureInactive,
            fn () => $this->service->update(7, $this->updateDto(['procedureId' => 99]), self::NOTARY)
        );
    }

    public function testUpdateWritesInTheExpectedOrderAndNeverTouchesIdentity(): void
    {
        $this->givenStored();
        $this->givenCalculatedMode();
        $this->givenTaxCatalog([1 => 1]);

        $order = [];
        $this->repository->method('deleteDetails')->willReturnCallback(function () use (&$order): void {
            $order[] = 'deleteDetails';
        });
        $this->repository->method('insertDetails')->willReturnCallback(function () use (&$order): void {
            $order[] = 'insertDetails';
        });
        $this->repository->method('update')->willReturnCallback(function () use (&$order): void {
            $order[] = 'update';
        });

        $this->service->update(7, $this->updateDto(['details' => [['taxId' => 1, 'amount' => 10]]]), self::NOTARY);

        $this->assertSame(['deleteDetails', 'insertDetails', 'update'], $order);
    }

    public function testUpdateUsesTheModeOfTheRequestedProcedure(): void
    {
        // Stored in procedure 10; the request moves it to 99, which has no taxes configured (manual)
        $stored = $this->storedTransaction(7, self::PROCEDURE, [new PaymentTransactionDetail(1, 'Tax 1', 1, 500.0, null, '')]);
        $this->givenStored($stored);
        $this->givenProcedure(1, 99);
        $this->procedureTaxRepository->method('findActiveByProcedure')->with(99, self::NOTARY)->willReturn([]);
        $this->repository->expects($this->once())->method('deleteDetails')->with(7);
        $this->repository->expects($this->once())->method('insertDetails')->with(7, []);
        $this->repository->expects($this->once())->method('update')
            ->with(7, self::NOTARY, $this->anything(), 250.0, 3250.0);

        $this->service->update(7, $this->updateDto([
            'procedureId' => 99, 'taxes' => 250, 'details' => [['taxId' => 1, 'amount' => 500]],
        ]), self::NOTARY);
    }

    public function testUpdateWithEmptyDetailsInCalculatedModeClearsThemAndZeroesTaxes(): void
    {
        $stored = $this->storedTransaction(7, self::PROCEDURE, [new PaymentTransactionDetail(1, 'Tax 1', 1, 500.0, null, '')]);
        $this->givenStored($stored);
        $this->givenCalculatedMode();
        $this->givenTaxCatalog([]);
        $this->repository->expects($this->once())->method('deleteDetails')->with(7);
        $this->repository->expects($this->once())->method('update')
            ->with(7, self::NOTARY, $this->anything(), 0.0, 3000.0);

        $this->service->update(7, $this->updateDto(['details' => []]), self::NOTARY);
    }

    public function testUpdateKeepsAnExistingDetailWhoseTaxIsNowInactiveButIgnoresNewInactiveOnes(): void
    {
        $stored = $this->storedTransaction(7, self::PROCEDURE, [new PaymentTransactionDetail(1, 'Tax 1', 1, 500.0, null, '')]);
        $this->givenStored($stored);
        $this->givenCalculatedMode();
        // both taxes are inactive now; only tax 1 was already part of the transaction
        $this->givenTaxCatalog([1 => 2, 2 => 2]);

        $inserted = null;
        $this->repository->method('insertDetails')->willReturnCallback(function (int $id, array $items) use (&$inserted): void {
            $inserted = $items;
        });
        $this->repository->expects($this->once())->method('update')
            ->with(7, self::NOTARY, $this->anything(), 500.0, 3500.0);

        $this->service->update(7, $this->updateDto([
            'details' => [['taxId' => 1, 'amount' => 500], ['taxId' => 2, 'amount' => 300]],
        ]), self::NOTARY);

        $this->assertSame([1], self::taxIds($inserted));
    }

    public function testUpdateRunsEntirelyInsideOneTransaction(): void
    {
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $service = new PaymentTransactionService(
            $this->repository, $this->clientRepository, $this->procedureRepository,
            $this->taxRepository, $this->procedureTaxRepository, $transactions
        );
        $this->givenStored();
        $this->givenManualMode();

        $service->update(7, $this->updateDto(), self::NOTARY);
    }
}
