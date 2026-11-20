<?php

declare(strict_types=1);

namespace Tests\Module\PaymentStatement\Renderer;

use App\Module\PaymentStatement\DTO\StatementLedgerRow;
use App\Module\PaymentStatement\DTO\StatementPdfData;
use App\Module\PaymentStatement\Entity\BalanceStatus;
use App\Module\PaymentStatement\Entity\LedgerRowKind;
use App\Module\PaymentStatement\Exception\StatementGenerationException;
use App\Module\PaymentStatement\Renderer\StatementHtmlBuilder;
use App\Module\PaymentStatement\Renderer\StatementPdfRenderer;
use App\Module\Receipt\DTO\ReceiptClient;
use App\Module\Receipt\DTO\ReceiptNotary;
use App\Shared\Pdf\DompdfDocumentRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;
use PHPUnit\Framework\TestCase;

/** Smoke tests: the visual review (accents, logo, footer, black and white) is done manually. */
final class StatementPdfRendererTest extends TestCase
{
    // A 1x1 transparent PNG
    private const LOGO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private StatementPdfRenderer $renderer;

    private string|false $previousErrorLog = false;
    private ?string $logFile = null;

    protected function setUp(): void
    {
        $this->renderer = new StatementPdfRenderer(
            new StatementHtmlBuilder(),
            new DompdfDocumentRenderer(__DIR__ . '/../../../../src/Module/PaymentStatement/Renderer/templates')
        );

        $this->logFile = tempnam(sys_get_temp_dir(), 'statement-log');
        $this->previousErrorLog = ini_set('error_log', (string) $this->logFile);
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }
        if ($this->logFile !== null && is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /** @return list<StatementLedgerRow> */
    private function rows(int $count, string $concept = 'Compraventa de inmueble – ESC-1'): array
    {
        $rows = [];
        $balance = 0.0;
        for ($i = 1; $i <= $count; $i++) {
            $balance = round($balance + 100.0, 2);
            $rows[] = new StatementLedgerRow(LedgerRowKind::Charge, '2026-03-10', sprintf('T-%06d', $i), $concept, 100.0, null, $balance);
        }

        return $rows;
    }

    /** @param list<StatementLedgerRow> $rows */
    private function data(array $rows, ?string $logo = null, string $clientName = 'Iñaki Ángeles Ñúñez', bool $hasMovements = true): StatementPdfData
    {
        $balance = $rows === [] ? 0.0 : $rows[array_key_last($rows)]->balance;

        return new StatementPdfData(
            clientId: 5,
            notary: new ReceiptNotary('Notaría 42', 'Lic. Juan Pérez', 'XAXX010101000', 'Av. Central 100', 'Querétaro', '4421234567', $logo),
            client: new ReceiptClient($clientName, '4429876543', 'inaki@example.com'),
            fromDate: null,
            toDate: null,
            rows: $rows,
            hasMovements: $hasMovements,
            pendingAmount: 0.0,
            totalCharges: $balance,
            totalPayments: 0.0,
            balance: $balance,
            status: BalanceStatus::fromBalance($balance),
            amountInWords: $balance > 0 ? 'UN PESO 00/100 M.N.' : null,
            generatedAt: new \DateTimeImmutable('2026-03-31 10:00:00', new \DateTimeZone('America/Mexico_City')),
        );
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page(?!s)\b#', $pdf);
    }

    public function testRendersAValidPdf(): void
    {
        $pdf = $this->renderer->render($this->data($this->rows(3)));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringEndsWith('%%EOF', rtrim($pdf));
        $this->assertSame(1, $this->pageCount($pdf));
    }

    public function testRendersWithAndWithoutLogo(): void
    {
        $this->assertStringStartsWith('%PDF-', $this->renderer->render($this->data($this->rows(2), self::LOGO)));
        $this->assertStringStartsWith('%PDF-', $this->renderer->render($this->data($this->rows(2))));
    }

    public function testRendersAnEmptyStatement(): void
    {
        $pdf = $this->renderer->render($this->data([], hasMovements: false));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, $this->pageCount($pdf));
    }

    public function testManyRowsSpanSeveralPages(): void
    {
        $pdf = $this->renderer->render($this->data($this->rows(150)));

        $this->assertGreaterThan(1, $this->pageCount($pdf));
        $this->assertSame('', (string) file_get_contents((string) $this->logFile), 'no warning below the volume threshold');
    }

    public function testLongTextInACellDoesNotBreakTheRender(): void
    {
        $pdf = $this->renderer->render($this->data($this->rows(3, str_repeat('Texto-muy-largo-sin-espacios-áéíóú-', 30))));

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function testMaliciousMarkupDoesNotBreakRendering(): void
    {
        $pdf = $this->renderer->render($this->data($this->rows(1, '<script>alert(1)</script><img src="http://127.0.0.1/x.png">'), clientName: '<script>alert(1)</script>'));

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function testVolumeAboveTheThresholdIsLogged(): void
    {
        // The warning is written before rendering, so a stub avoids paying for 400+ real rows (~100 MB)
        $stub = new class (sys_get_temp_dir()) extends DompdfDocumentRenderer {
            public function render(string $html, \DateTimeImmutable $generatedAt): string
            {
                return '%PDF-stub';
            }
        };
        $renderer = new StatementPdfRenderer(new StatementHtmlBuilder(), $stub);

        $renderer->render($this->data($this->rows(StatementPdfRenderer::ROW_WARNING_THRESHOLD)));
        $this->assertSame('', (string) file_get_contents((string) $this->logFile), 'the threshold itself does not warn');

        $renderer->render($this->data($this->rows(StatementPdfRenderer::ROW_WARNING_THRESHOLD + 1)));
        $this->assertStringContainsString('[statement] client 5: 401 ledger rows (> 400)', (string) file_get_contents((string) $this->logFile));
    }

    public function testRenderFailureIsWrappedAndNeverLeaksOutput(): void
    {
        $failing = new class (sys_get_temp_dir()) extends DompdfDocumentRenderer {
            protected function createDompdf(Options $options): Dompdf
            {
                return new class ($options) extends Dompdf {
                    public function render(): void
                    {
                        echo 'partial output';

                        throw new \RuntimeException('layout exploded');
                    }
                };
            }
        };
        $renderer = new StatementPdfRenderer(new StatementHtmlBuilder(), $failing);
        $level = ob_get_level();

        try {
            $renderer->render($this->data($this->rows(1)));
            $this->fail('Expected StatementGenerationException');
        } catch (StatementGenerationException $e) {
            $this->assertStringContainsString('layout exploded', $e->getMessage());
            $this->assertInstanceOf(\Throwable::class, $e->getPrevious());
        }

        $this->assertSame($level, ob_get_level(), 'output buffers must be restored');
    }
}
