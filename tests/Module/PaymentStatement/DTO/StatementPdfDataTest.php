<?php

declare(strict_types=1);

namespace Tests\Module\PaymentStatement\DTO;

use App\Module\PaymentStatement\DTO\StatementPdfData;
use App\Module\PaymentStatement\Entity\BalanceStatus;
use App\Module\Receipt\DTO\ReceiptClient;
use App\Module\Receipt\DTO\ReceiptNotary;
use PHPUnit\Framework\TestCase;

final class StatementPdfDataTest extends TestCase
{
    private function data(?string $from = null, ?string $to = null, int $clientId = 5): StatementPdfData
    {
        return new StatementPdfData(
            clientId: $clientId,
            notary: new ReceiptNotary('Notaría 42', '', '', '', '', '', null),
            client: new ReceiptClient('María García', '', ''),
            fromDate: $from,
            toDate: $to,
            rows: [],
            hasMovements: false,
            pendingAmount: 0.0,
            totalCharges: 0.0,
            totalPayments: 0.0,
            balance: 0.0,
            status: BalanceStatus::Settled,
            amountInWords: null,
            generatedAt: new \DateTimeImmutable('2026-03-31 10:00:00'),
        );
    }

    public function testPeriodLabelWithBothDates(): void
    {
        $this->assertSame('Del 01/03/2026 al 31/03/2026', $this->data('2026-03-01', '2026-03-31')->periodLabel());
    }

    public function testPeriodLabelWithOnlyFrom(): void
    {
        $this->assertSame('Desde 01/03/2026', $this->data('2026-03-01')->periodLabel());
    }

    public function testPeriodLabelWithOnlyTo(): void
    {
        $this->assertSame('Hasta 31/03/2026', $this->data(null, '2026-03-31')->periodLabel());
    }

    public function testPeriodLabelWithoutFilters(): void
    {
        $this->assertSame('Todos los movimientos', $this->data()->periodLabel());
    }

    public function testFileName(): void
    {
        $this->assertSame('estado-de-cuenta-C-000005.pdf', $this->data()->fileName());
        $this->assertSame('estado-de-cuenta-C-1234567.pdf', $this->data(clientId: 1234567)->fileName(), 'big ids are never truncated');
    }

    public function testFileNameOnlyHasSafeCharacters(): void
    {
        foreach ([1, 5, 999999, 1000000, PHP_INT_MAX] as $id) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/D', $this->data(clientId: $id)->fileName());
        }
    }
}
