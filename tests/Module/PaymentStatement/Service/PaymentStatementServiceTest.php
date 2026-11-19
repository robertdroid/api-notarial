<?php

declare(strict_types=1);

namespace Tests\Module\PaymentStatement\Service;

use App\Module\PaymentClient\Entity\PaymentClient;
use App\Module\PaymentClient\Repository\PaymentClientRepository;
use App\Module\PaymentStatement\DTO\StatementQuery;
use App\Module\PaymentStatement\Entity\StatementPayment;
use App\Module\PaymentStatement\Entity\StatementTransaction;
use App\Module\PaymentStatement\Repository\StatementRepository;
use App\Module\PaymentStatement\Service\PaymentStatementService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PaymentStatementServiceTest extends TestCase
{
    private const NOTARY = 1;
    private const CLIENT = 5;

    private PaymentStatementService $service;
    private PaymentClientRepository&MockObject $clientRepository;
    private StatementRepository&MockObject $statementRepository;

    protected function setUp(): void
    {
        $this->clientRepository = $this->createMock(PaymentClientRepository::class);
        $this->statementRepository = $this->createMock(StatementRepository::class);

        $this->service = new PaymentStatementService($this->clientRepository, $this->statementRepository);
    }

    // ---- helpers ---------------------------------------------------------

    private function client(int $status = PaymentClient::STATUS_ACTIVE): PaymentClient
    {
        return new PaymentClient(self::CLIENT, self::NOTARY, 'María García López', '5551234567', 'maria@example.com', $status);
    }

    private function givenClient(?PaymentClient $client): void
    {
        $this->clientRepository->method('findByIdAndNotary')->with(self::CLIENT, self::NOTARY)->willReturn($client);
    }

    private function transaction(float $total, string $date = '2026-03-10', int $id = 25): StatementTransaction
    {
        return new StatementTransaction($id, 'uuid' . $id, $date, 'Compraventa', 'ESC-1', $total, 0.0, $total);
    }

    private function payment(float $amount, ?string $date = '2026-03-15', int $id = 10): StatementPayment
    {
        return new StatementPayment($id, 'p' . $id, $date, 'Anticipo', $amount);
    }

    /**
     * @param list<StatementTransaction> $transactions
     * @param list<StatementPayment>     $payments
     */
    private function givenMovements(array $transactions, array $payments): void
    {
        $this->statementRepository->method('findActiveTransactions')->willReturn($transactions);
        $this->statementRepository->method('findPayments')->willReturn($payments);
    }

    private function query(array $params = []): StatementQuery
    {
        return StatementQuery::fromArray($params);
    }

    // ---- client ----------------------------------------------------------

    public function testUnknownClientReturnsNullAndDoesNotQueryMovements(): void
    {
        $this->givenClient(null);
        $this->statementRepository->expects($this->never())->method('findActiveTransactions');
        $this->statementRepository->expects($this->never())->method('findPayments');
        $this->statementRepository->expects($this->never())->method('sumActiveTransactionsBefore');
        $this->statementRepository->expects($this->never())->method('sumPaymentsBefore');

        $this->assertNull($this->service->build(self::CLIENT, self::NOTARY, $this->query()));
    }

    public function testInactiveClientGetsTheFullStatement(): void
    {
        $this->givenClient($this->client(PaymentClient::STATUS_ACTIVE + 1));
        $this->givenMovements([$this->transaction(1160.0)], [$this->payment(500.0)]);

        $statement = $this->service->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertNotNull($statement);
        $this->assertSame(660.0, $statement->balance);
    }

    public function testClientNameIsTheCurrentOne(): void
    {
        $this->givenClient($this->client());
        $this->givenMovements([], []);

        $statement = $this->service->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertSame(self::CLIENT, $statement->clientId);
        $this->assertSame('María García López', $statement->clientName);
    }

    // ---- totals ----------------------------------------------------------

    public function testNoMovementsMeansEverythingIsZero(): void
    {
        $this->givenClient($this->client());
        $this->givenMovements([], []);

        $statement = $this->service->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertSame([], $statement->transactions);
        $this->assertSame([], $statement->payments);
        $this->assertSame(0.0, $statement->pendingAmount);
        $this->assertSame(0.0, $statement->totalCharges);
        $this->assertSame(0.0, $statement->totalPayments);
        $this->assertSame(0.0, $statement->balance);
    }

    public function testWithoutFromThePreviousBalanceQueriesAreNotExecuted(): void
    {
        $this->givenClient($this->client());
        $this->givenMovements([$this->transaction(1160.0)], [$this->payment(500.0)]);
        $this->statementRepository->expects($this->never())->method('sumActiveTransactionsBefore');
        $this->statementRepository->expects($this->never())->method('sumPaymentsBefore');

        $statement = $this->service->build(self::CLIENT, self::NOTARY, $this->query(['to' => '2026-03-31']));

        $this->assertSame(0.0, $statement->pendingAmount);
        $this->assertSame(1160.0, $statement->totalCharges);
        $this->assertSame(500.0, $statement->totalPayments);
        $this->assertSame(660.0, $statement->balance);
        $this->assertNull($statement->fromDate);
        $this->assertSame('2026-03-31', $statement->toDate);
    }

    public function testWithFromThePendingAmountFeedsTheBalance(): void
    {
        $this->givenClient($this->client());
        $this->givenMovements([$this->transaction(1160.0)], [$this->payment(500.0)]);
        $this->statementRepository->method('sumActiveTransactionsBefore')->willReturn(3000.0);
        $this->statementRepository->method('sumPaymentsBefore')->willReturn(1000.0);

        $statement = $this->service->build(self::CLIENT, self::NOTARY, $this->query(['from' => '2026-03-01']));

        $this->assertSame(2000.0, $statement->pendingAmount);
        $this->assertSame(1160.0, $statement->totalCharges);
        $this->assertSame(500.0, $statement->totalPayments);
        $this->assertSame(2660.0, $statement->balance);
        $this->assertSame('2026-03-01', $statement->fromDate);
    }

    public function testCreditBalanceIsNegative(): void
    {
        $this->givenClient($this->client());
        $this->givenMovements([$this->transaction(100.0)], [$this->payment(350.5)]);

        $statement = $this->service->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertSame(-250.5, $statement->balance);
    }

    public function testRangeWithoutMovementsButWithPreviousBalance(): void
    {
        $this->givenClient($this->client());
        $this->givenMovements([], []);
        $this->statementRepository->method('sumActiveTransactionsBefore')->willReturn(800.0);
        $this->statementRepository->method('sumPaymentsBefore')->willReturn(300.0);

        $statement = $this->service->build(self::CLIENT, self::NOTARY, $this->query(['from' => '2026-03-01', 'to' => '2026-03-31']));

        $this->assertSame(500.0, $statement->pendingAmount);
        $this->assertSame(0.0, $statement->totalCharges);
        $this->assertSame(0.0, $statement->totalPayments);
        $this->assertSame($statement->pendingAmount, $statement->balance);
    }

    public function testTotalsDoNotDriftWithFloatArithmetic(): void
    {
        $this->givenClient($this->client());
        $this->givenMovements(
            [$this->transaction(0.1, id: 1), $this->transaction(0.2, id: 2)],
            []
        );

        $statement = $this->service->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertSame(0.3, $statement->totalCharges);
        $this->assertSame(0.3, $statement->balance);
    }

    public function testRowAmountsAreRoundedToTwoDecimals(): void
    {
        $this->givenClient($this->client());
        $this->givenMovements(
            [new StatementTransaction(1, 'u', '2026-03-10', '', '', 100.004, 16.006, 116.01)],
            [$this->payment(10.126)]
        );

        $statement = $this->service->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertSame(100.0, $statement->transactions[0]->fee);
        $this->assertSame(16.01, $statement->transactions[0]->taxes);
        $this->assertSame(116.01, $statement->transactions[0]->total);
        $this->assertSame(10.13, $statement->payments[0]->amount);
    }

    public function testBalanceIsExactlyPendingPlusChargesMinusPayments(): void
    {
        $this->givenClient($this->client());
        $this->givenMovements([$this->transaction(0.1), $this->transaction(0.7, id: 26)], [$this->payment(0.3)]);
        $this->statementRepository->method('sumActiveTransactionsBefore')->willReturn(0.2);
        $this->statementRepository->method('sumPaymentsBefore')->willReturn(0.1);

        $statement = $this->service->build(self::CLIENT, self::NOTARY, $this->query(['from' => '2026-03-01']));

        $this->assertSame(0.1, $statement->pendingAmount);
        $this->assertSame(0.8, $statement->totalCharges);
        $this->assertSame(0.3, $statement->totalPayments);
        $this->assertSame(0.6, $statement->balance);
    }

    // ---- multi-tenant / boundaries ----------------------------------------

    public function testEveryQueryReceivesNotaryAndClientAndTheBoundaries(): void
    {
        $this->givenClient($this->client());

        $this->statementRepository->expects($this->once())->method('findActiveTransactions')
            ->with(self::NOTARY, self::CLIENT, '2026-03-01 00:00:00', '2026-04-01 00:00:00')
            ->willReturn([]);
        $this->statementRepository->expects($this->once())->method('findPayments')
            ->with(self::NOTARY, self::CLIENT, '2026-03-01 00:00:00', '2026-04-01 00:00:00')
            ->willReturn([]);
        $this->statementRepository->expects($this->once())->method('sumActiveTransactionsBefore')
            ->with(self::NOTARY, self::CLIENT, '2026-03-01 00:00:00')
            ->willReturn(0.0);
        $this->statementRepository->expects($this->once())->method('sumPaymentsBefore')
            ->with(self::NOTARY, self::CLIENT, '2026-03-01 00:00:00')
            ->willReturn(0.0);

        $this->service->build(self::CLIENT, self::NOTARY, $this->query(['from' => '2026-03-01', 'to' => '2026-03-31']));
    }

    public function testUndatedPaymentCountsWhenThereIsNoRange(): void
    {
        $this->givenClient($this->client());
        $this->givenMovements([], [$this->payment(200.0, null, 11)]);

        $statement = $this->service->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertNull($statement->payments[0]->date);
        $this->assertSame(200.0, $statement->totalPayments);
    }
}
