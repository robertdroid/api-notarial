<?php

declare(strict_types=1);

namespace Tests\Module\Receipt\Renderer;

use App\Module\Receipt\DTO\ReceiptClient;
use App\Module\Receipt\DTO\ReceiptData;
use App\Module\Receipt\DTO\ReceiptNotary;
use App\Module\Receipt\DTO\ReceiptTaxLine;
use App\Module\Receipt\Entity\ReceiptType;
use App\Module\Receipt\Exception\ReceiptGenerationException;
use App\Module\Receipt\Renderer\QrCodeGenerator;
use App\Module\Receipt\Renderer\ReceiptHtmlBuilder;
use App\Module\Receipt\Renderer\ReceiptPdfRenderer;
use PHPUnit\Framework\TestCase;

/** Smoke tests: the visual review (accents, watermark, footer, QR) is done manually by QA. */
final class ReceiptPdfRendererTest extends TestCase
{
    private ReceiptPdfRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ReceiptPdfRenderer(new ReceiptHtmlBuilder(), new QrCodeGenerator());
    }

    /** @param ReceiptTaxLine[] $taxLines */
    private function transaction(array $taxLines = [], bool $cancelled = false, string $clientName = 'Iñaki Ángeles Ñúñez'): ReceiptData
    {
        return new ReceiptData(
            type: ReceiptType::Transaction,
            id: 123,
            uuid: 'Us2Gvq',
            date: '2024-04-02',
            notary: new ReceiptNotary('Notaría 42', 'Lic. Juan Pérez', 'XAXX010101000', 'Av. Central 100', 'Querétaro', '4421234567', null),
            client: new ReceiptClient($clientName, '4429876543', 'inaki@example.com'),
            total: 1350.0,
            amountInWords: 'MIL TRESCIENTOS CINCUENTA PESOS 00/100 M.N.',
            cancelled: $cancelled,
            generatedAt: new \DateTimeImmutable('2024-04-02 10:00:00', new \DateTimeZone('America/Mexico_City')),
            procedureName: 'Compraventa – inmueble',
            reference: 'REF-1',
            responsible: 'Ana López',
            fee: 1000.0,
            taxes: 350.0,
            taxLines: $taxLines,
        );
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page(?!s)\b#', $pdf);
    }

    public function testRendersAValidPdf(): void
    {
        $pdf = $this->renderer->render($this->transaction([new ReceiptTaxLine(1, 'ISAI', 350.0)]));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringEndsWith('%%EOF', rtrim($pdf));
        $this->assertSame(1, $this->pageCount($pdf));
    }

    public function testRendersCancelledTransactionAndPayment(): void
    {
        $cancelled = $this->renderer->render($this->transaction(cancelled: true));
        $payment = $this->renderer->render(new ReceiptData(
            type: ReceiptType::Payment,
            id: 7,
            uuid: 'Pay123',
            date: null,
            notary: new ReceiptNotary('Notaría 42', '', '', '', '', '', null),
            client: new ReceiptClient('', '', ''),
            total: 5000.5,
            amountInWords: 'CINCO MIL PESOS 50/100 M.N.',
            cancelled: false,
            generatedAt: new \DateTimeImmutable('2024-04-02 10:00:00'),
        ));

        $this->assertStringStartsWith('%PDF-', $cancelled);
        $this->assertStringStartsWith('%PDF-', $payment);
    }

    public function testManyTaxLinesSpanSeveralPages(): void
    {
        $lines = [];
        for ($i = 1; $i <= 40; $i++) {
            $lines[] = new ReceiptTaxLine(1, 'Impuesto ' . $i, 10.0 * $i);
        }

        $pdf = $this->renderer->render($this->transaction($lines));

        $this->assertGreaterThan(1, $this->pageCount($pdf));
    }

    public function testMaliciousMarkupDoesNotBreakRendering(): void
    {
        $pdf = $this->renderer->render($this->transaction(clientName: '<script>alert(1)</script><img src="http://127.0.0.1/x.png">'));

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function testRenderFailureIsWrappedAndNeverLeaksOutput(): void
    {
        $failingQr = new class () extends QrCodeGenerator {
            public function dataUri(string $uuid): string
            {
                echo 'partial output';

                throw new \RuntimeException('gd missing');
            }
        };
        $renderer = new ReceiptPdfRenderer(new ReceiptHtmlBuilder(), $failingQr);
        $level = ob_get_level();

        try {
            $renderer->render($this->transaction());
            $this->fail('Expected ReceiptGenerationException');
        } catch (ReceiptGenerationException $e) {
            $this->assertStringContainsString('gd missing', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }

        $this->assertSame($level, ob_get_level(), 'output buffers must be restored');
    }
}
