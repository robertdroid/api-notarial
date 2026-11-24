<?php

declare(strict_types=1);

namespace Tests\Module\PaymentStatement\Service;

use App\Module\PaymentStatement\Entity\ClientStatement;
use App\Module\PaymentStatement\Entity\LedgerRowKind;
use App\Module\PaymentStatement\Entity\StatementPayment;
use App\Module\PaymentStatement\Entity\StatementTransaction;
use App\Module\PaymentStatement\Service\StatementLedgerBuilder;
use PHPUnit\Framework\TestCase;

final class StatementLedgerBuilderTest extends TestCase
{
    private StatementLedgerBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new StatementLedgerBuilder();
    }

    // ---- helpers ---------------------------------------------------------

    private function transaction(int $id, float $total, string $date = '2026-03-10', string $procedure = 'Compraventa', string $reference = 'ESC-1'): StatementTransaction
    {
        return new StatementTransaction($id, 'uuid' . $id, $date, $procedure, $reference, $total, 0.0, $total);
    }

    private function payment(int $id, float $amount, ?string $date = '2026-03-10', string $comment = 'Anticipo'): StatementPayment
    {
        return new StatementPayment($id, 'p' . $id, $date, $comment, $amount);
    }

    /**
     * @param list<StatementTransaction> $transactions
     * @param list<StatementPayment>     $payments
     */
    private function statement(array $transactions, array $payments, ?string $from = null, float $pending = 0.0, float $balance = 0.0): ClientStatement
    {
        return new ClientStatement(5, 'María García', $from, null, $transactions, $payments, $pending, 0.0, 0.0, $balance);
    }

    // ---- order -----------------------------------------------------------

    public function testRunningBalanceFollowsChargesAndPayments(): void
    {
        $rows = $this->builder->build($this->statement(
            [$this->transaction(1, 1000.0, '2026-03-01'), $this->transaction(2, 200.0, '2026-03-03')],
            [$this->payment(1, 400.0, '2026-03-02')]
        ));

        $this->assertSame([1000.0, 600.0, 800.0], array_map(static fn ($r) => $r->balance, $rows));
        $this->assertSame(
            [LedgerRowKind::Charge, LedgerRowKind::Payment, LedgerRowKind::Charge],
            array_map(static fn ($r) => $r->kind, $rows)
        );
    }

    public function testRowsAreOrderedByDateEvenWhenTheInputIsNot(): void
    {
        $rows = $this->builder->build($this->statement(
            [$this->transaction(2, 10.0, '2026-03-20'), $this->transaction(1, 10.0, '2026-03-05')],
            [$this->payment(1, 5.0, '2026-03-12')]
        ));

        $this->assertSame(['T-000001', 'C-000001', 'T-000002'], array_map(static fn ($r) => $r->folio, $rows));
    }

    public function testChargeGoesBeforePaymentOnTheSameDayEvenWithAHigherId(): void
    {
        $rows = $this->builder->build($this->statement(
            [$this->transaction(99, 100.0, '2026-03-10')],
            [$this->payment(1, 40.0, '2026-03-10')]
        ));

        $this->assertSame(['T-000099', 'C-000001'], array_map(static fn ($r) => $r->folio, $rows));
        $this->assertSame([100.0, 60.0], array_map(static fn ($r) => $r->balance, $rows));
    }

    public function testSameDayAndTypeIsTiedById(): void
    {
        $rows = $this->builder->build($this->statement(
            [$this->transaction(5, 10.0), $this->transaction(3, 10.0)],
            [$this->payment(8, 1.0), $this->payment(2, 1.0)]
        ));

        $this->assertSame(['T-000003', 'T-000005', 'C-000002', 'C-000008'], array_map(static fn ($r) => $r->folio, $rows));
    }

    public function testUndatedPaymentsGoLastOrderedById(): void
    {
        $rows = $this->builder->build($this->statement(
            [$this->transaction(1, 100.0, '2026-03-10')],
            [$this->payment(7, 10.0, null), $this->payment(3, 20.0, null), $this->payment(9, 5.0, '2026-01-01')]
        ));

        $this->assertSame(['C-000009', 'T-000001', 'C-000003', 'C-000007'], array_map(static fn ($r) => $r->folio, $rows));
        $this->assertNull($rows[2]->date);
    }

    public function testOrderIgnoresTheInputOrderOfEqualItems(): void
    {
        $a = $this->builder->build($this->statement([$this->transaction(1, 1.0), $this->transaction(2, 1.0)], []));
        $b = $this->builder->build($this->statement([$this->transaction(2, 1.0), $this->transaction(1, 1.0)], []));

        $this->assertEquals($a, $b);
    }

    // ---- opening row -----------------------------------------------------

    public function testOpeningRowOnlyWhenFromIsSet(): void
    {
        $withFrom = $this->builder->build($this->statement([$this->transaction(1, 200.0)], [], '2026-03-01', 500.0));
        $without = $this->builder->build($this->statement([$this->transaction(1, 200.0)], []));

        $this->assertCount(2, $withFrom);
        $this->assertSame(LedgerRowKind::Opening, $withFrom[0]->kind);
        $this->assertSame('Saldo anterior', $withFrom[0]->concept);
        $this->assertNull($withFrom[0]->date);
        $this->assertSame('', $withFrom[0]->folio);
        $this->assertNull($withFrom[0]->charge);
        $this->assertNull($withFrom[0]->payment);
        $this->assertSame(500.0, $withFrom[0]->balance);
        $this->assertSame(700.0, $withFrom[1]->balance, 'the running balance starts at the opening balance');

        $this->assertCount(1, $without);
        $this->assertSame(LedgerRowKind::Charge, $without[0]->kind);
    }

    public function testOpeningRowIsKeptWithoutMovements(): void
    {
        $rows = $this->builder->build($this->statement([], [], '2026-03-01', 123.45));

        $this->assertCount(1, $rows);
        $this->assertSame(123.45, $rows[0]->balance);
    }

    public function testNoMovementsAndNoFromGivesNoRows(): void
    {
        $this->assertSame([], $this->builder->build($this->statement([], [])));
    }

    // ---- balance ---------------------------------------------------------

    public function testNegativeBalanceIsKept(): void
    {
        $rows = $this->builder->build($this->statement([$this->transaction(1, 100.0, '2026-03-01')], [$this->payment(1, 400.0, '2026-03-02')]));

        $this->assertSame(-300.0, $rows[1]->balance);
    }

    public function testLastRowBalanceEqualsTheStatementBalance(): void
    {
        $statement = $this->statement(
            [$this->transaction(1, 1000.0, '2026-03-01'), $this->transaction(2, 250.5, '2026-03-04')],
            [$this->payment(1, 400.0, '2026-03-02'), $this->payment(2, 100.25, '2026-03-05')],
            '2026-03-01',
            75.0,
            825.25
        );

        $rows = $this->builder->build($statement);

        $this->assertSame($statement->balance, $rows[array_key_last($rows)]->balance);
    }

    public function testCentsDoNotDrift(): void
    {
        $rows = $this->builder->build($this->statement(
            [$this->transaction(1, 0.1, '2026-03-01'), $this->transaction(2, 0.2, '2026-03-02')],
            []
        ));

        $this->assertSame(0.3, $rows[1]->balance);
    }

    public function testManySmallAmountsStayExact(): void
    {
        $transactions = [];
        for ($i = 1; $i <= 100; $i++) {
            $transactions[] = $this->transaction($i, 0.1, '2026-03-01');
        }

        $rows = $this->builder->build($this->statement($transactions, []));

        $this->assertSame(10.0, $rows[99]->balance);
    }

    // ---- columns ---------------------------------------------------------

    public function testChargeAndPaymentFillTheirOwnColumn(): void
    {
        $rows = $this->builder->build($this->statement([$this->transaction(1, 300.0)], [$this->payment(1, 120.5)]));

        $this->assertSame(300.0, $rows[0]->charge);
        $this->assertNull($rows[0]->payment);
        $this->assertNull($rows[1]->charge);
        $this->assertSame(120.5, $rows[1]->payment);
    }

    public function testFoliosUseTheReceiptFormat(): void
    {
        $rows = $this->builder->build($this->statement([$this->transaction(123, 1.0)], [$this->payment(45, 1.0)]));

        $this->assertSame('T-000123', $rows[0]->folio);
        $this->assertSame('C-000045', $rows[1]->folio);
    }

    /** @return array<string, array{string, string, string}> */
    public static function transactionConcepts(): array
    {
        return [
            'procedure and reference' => ['Compraventa', 'ESC-1', 'Compraventa – ESC-1'],
            'only procedure' => ['Compraventa', '', 'Compraventa'],
            'only reference' => ['', 'ESC-1', 'ESC-1'],
            'neither' => ['', '', '—'],
            'blank spaces count as empty' => ['  ', ' ', '—'],
        ];
    }

    /** @dataProvider transactionConcepts */
    public function testTransactionConcept(string $procedure, string $reference, string $expected): void
    {
        $rows = $this->builder->build($this->statement([$this->transaction(1, 1.0, '2026-03-10', $procedure, $reference)], []));

        $this->assertSame($expected, $rows[0]->concept);
    }

    public function testPaymentConceptIsTheCommentOrAbono(): void
    {
        $rows = $this->builder->build($this->statement([], [
            $this->payment(1, 1.0, '2026-03-01', 'Pago de honorarios'),
            $this->payment(2, 1.0, '2026-03-02', ''),
            $this->payment(3, 1.0, '2026-03-03', "  \n "),
        ]));

        $this->assertSame(['Pago de honorarios', 'Abono', 'Abono'], array_map(static fn ($r) => $r->concept, $rows));
    }
}
