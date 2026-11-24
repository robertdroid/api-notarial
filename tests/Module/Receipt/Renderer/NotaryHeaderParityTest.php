<?php

declare(strict_types=1);

namespace Tests\Module\Receipt\Renderer;

use App\Module\PaymentStatement\DTO\StatementPdfData;
use App\Module\PaymentStatement\Entity\BalanceStatus;
use App\Module\PaymentStatement\Renderer\StatementHtmlBuilder;
use App\Module\Receipt\DTO\ReceiptClient;
use App\Module\Receipt\DTO\ReceiptData;
use App\Module\Receipt\DTO\ReceiptNotary;
use App\Module\Receipt\Entity\ReceiptType;
use App\Module\Receipt\Renderer\ReceiptHtmlBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The notary header is duplicated in receipt.php and statement.php (ADR-002 of spec 22).
 * This test keeps both copies identical.
 */
final class NotaryHeaderParityTest extends TestCase
{
    private const QR = 'data:image/png;base64,QRDATA';

    /** @return array<string, array{string}> */
    public static function rfcProvider(): array
    {
        return [
            'with RFC' => ['XAXX010101000'],
            'empty RFC' => [''],
            'blank RFC' => ['   '],
        ];
    }

    #[DataProvider('rfcProvider')]
    public function testReceiptAndStatementRenderTheSameNotaryHeader(string $rfc): void
    {
        $notary = new ReceiptNotary('Notaría 42', 'Lic. Juan Pérez', $rfc, 'Av. Central 100', 'Querétaro', '4421234567', null);

        $receipt = $this->extractHeader((new ReceiptHtmlBuilder())->build($this->receiptData($notary), self::QR));
        $statement = $this->extractHeader((new StatementHtmlBuilder())->build($this->statementData($notary)));

        $this->assertSame($receipt, $statement);
    }

    public function testParityAlsoHoldsWithEmptyNotaryNameAndPhone(): void
    {
        $notary = new ReceiptNotary('Notaría 42', '', '', '', '', '', null);

        $receipt = $this->extractHeader((new ReceiptHtmlBuilder())->build($this->receiptData($notary), self::QR));
        $statement = $this->extractHeader((new StatementHtmlBuilder())->build($this->statementData($notary)));

        $this->assertSame($receipt, $statement);
        $this->assertStringContainsString('<div>—</div>', $receipt);
        $this->assertStringContainsString('<div class="muted">---</div>', $receipt);
        $this->assertStringContainsString('<div class="muted">Tel. —</div>', $receipt);
    }

    /** Block from the notary-name line to the Tel. line, inclusive. */
    private function extractHeader(string $html): string
    {
        $found = preg_match('#<div class="notary-name">.*?Tel\. .*?</div>#s', $html, $matches);

        $this->assertSame(1, $found, 'notary header block not found');

        return $matches[0];
    }

    private function receiptData(ReceiptNotary $notary): ReceiptData
    {
        return new ReceiptData(
            type: ReceiptType::Transaction,
            id: 123,
            uuid: 'Us2Gvq',
            date: '2024-04-02',
            notary: $notary,
            client: new ReceiptClient('Iñaki Ángeles', '4429876543', 'inaki@example.com'),
            total: 1350.0,
            amountInWords: 'MIL TRESCIENTOS CINCUENTA PESOS 00/100 M.N.',
            cancelled: false,
            generatedAt: new \DateTimeImmutable('2024-04-02 10:00:00'),
            procedureName: 'Compraventa',
            reference: 'REF-1',
            responsible: 'Ana López',
            fee: 1000.0,
            taxes: 350.0,
            taxLines: [],
        );
    }

    private function statementData(ReceiptNotary $notary): StatementPdfData
    {
        return new StatementPdfData(
            clientId: 5,
            notary: $notary,
            client: new ReceiptClient('María García', '', 'maria@example.com'),
            fromDate: null,
            toDate: null,
            rows: [],
            hasMovements: false,
            pendingAmount: 0.0,
            totalCharges: 0.0,
            totalPayments: 0.0,
            balance: 0.0,
            status: BalanceStatus::fromBalance(0.0),
            amountInWords: null,
            generatedAt: new \DateTimeImmutable('2026-03-31 10:00:00', new \DateTimeZone('America/Mexico_City')),
        );
    }
}
