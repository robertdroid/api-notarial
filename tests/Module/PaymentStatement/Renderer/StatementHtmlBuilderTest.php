<?php

declare(strict_types=1);

namespace Tests\Module\PaymentStatement\Renderer;

use App\Module\PaymentStatement\DTO\StatementLedgerRow;
use App\Module\PaymentStatement\DTO\StatementPdfData;
use App\Module\PaymentStatement\Entity\BalanceStatus;
use App\Module\PaymentStatement\Entity\LedgerRowKind;
use App\Module\PaymentStatement\Renderer\StatementHtmlBuilder;
use App\Module\Receipt\DTO\ReceiptClient;
use App\Module\Receipt\DTO\ReceiptNotary;
use PHPUnit\Framework\TestCase;

final class StatementHtmlBuilderTest extends TestCase
{
    private StatementHtmlBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new StatementHtmlBuilder();
    }

    // ---- helpers ---------------------------------------------------------

    /** @param list<StatementLedgerRow> $rows */
    private function data(
        array $rows = [],
        string $clientName = 'María García',
        ?string $from = null,
        float $balance = 800.0,
        ?string $words = 'OCHOCIENTOS PESOS 00/100 M.N.',
        ?string $logo = null,
        bool $hasMovements = true,
        string $notaryName = 'Notaría 42',
        string $titularName = 'Lic. Juan Pérez',
        string $rfc = 'XAXX010101000',
    ): StatementPdfData {
        return new StatementPdfData(
            clientId: 5,
            notary: new ReceiptNotary($notaryName, $titularName, $rfc, 'Av. Central 100', 'Querétaro', '4421234567', $logo),
            client: new ReceiptClient($clientName, '', 'maria@example.com'),
            fromDate: $from,
            toDate: null,
            rows: $rows,
            hasMovements: $hasMovements,
            pendingAmount: 500.0,
            totalCharges: 1200.0,
            totalPayments: 400.0,
            balance: $balance,
            status: BalanceStatus::fromBalance($balance),
            amountInWords: $words,
            generatedAt: new \DateTimeImmutable('2026-03-31 10:00:00', new \DateTimeZone('America/Mexico_City')),
        );
    }

    private function charge(int $id = 1, string $concept = 'Compraventa – ESC-1', float $amount = 1000.0, float $balance = 1000.0): StatementLedgerRow
    {
        return new StatementLedgerRow(LedgerRowKind::Charge, '2026-03-10', sprintf('T-%06d', $id), $concept, $amount, null, $balance);
    }

    private function payment(int $id = 1, string $concept = 'Anticipo', ?string $date = '2026-03-15', float $amount = 400.0, float $balance = 600.0): StatementLedgerRow
    {
        return new StatementLedgerRow(LedgerRowKind::Payment, $date, sprintf('C-%06d', $id), $concept, null, $amount, $balance);
    }

    // ---- escape ----------------------------------------------------------

    public function testUserInputIsEscaped(): void
    {
        $html = $this->builder->build($this->data(
            [
                $this->charge(1, '<img src="http://127.0.0.1/x.png"> – <script>alert(2)</script>'),
                $this->payment(1, '<script>alert(3)</script>'),
            ],
            clientName: '<script>alert(1)</script>',
            notaryName: '<b>Notaría</b>'
        ));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src="http', $html);
        $this->assertStringNotContainsString('<b>Notaría</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(3)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=&quot;http://127.0.0.1/x.png&quot;&gt;', $html);
    }

    public function testNoPhpIsExecutedFromData(): void
    {
        $html = $this->builder->build($this->data([$this->charge(1, '<?php echo "pwned"; ?>')]));

        $this->assertStringNotContainsString('pwned"; ?>', $html);
        $this->assertStringContainsString('&lt;?php', $html);
    }

    public function testNullNeverReachesTheDocument(): void
    {
        $html = $this->builder->build($this->data([$this->charge(), $this->payment(date: null)], words: null));

        $this->assertStringNotContainsString('null', $html);
        $this->assertStringNotContainsString('NULL', $html);
    }

    // ---- ledger ----------------------------------------------------------

    public function testLedgerRowsAreFormatted(): void
    {
        $html = $this->builder->build($this->data([$this->charge(123, 'Compraventa – ESC-1', 1234.5, 1234.5), $this->payment(45, 'Anticipo', '2026-03-15', 400.0, 834.5)]));

        $this->assertStringContainsString('T-000123', $html);
        $this->assertStringContainsString('10/03/2026', $html);
        $this->assertStringContainsString('Compraventa – ESC-1', $html);
        $this->assertStringContainsString('$1,234.50', $html);
        $this->assertStringContainsString('C-000045', $html);
        $this->assertStringContainsString('15/03/2026', $html);
        $this->assertStringContainsString('$834.50', $html);
    }

    public function testPaymentWithoutDateShowsADash(): void
    {
        $html = $this->builder->build($this->data([$this->payment(date: null)]));

        $this->assertStringContainsString('<td class="date">—</td>', $html);
    }

    public function testNegativeBalanceIsPrintedWithItsSign(): void
    {
        $html = $this->builder->build($this->data([$this->payment(balance: -300.0)], balance: -300.0, words: 'TRESCIENTOS PESOS 00/100 M.N.'));

        $this->assertStringContainsString('-$300.00', $html);
        $this->assertStringNotContainsString('$-300.00', $html);
    }

    public function testOpeningRowAndOpeningBalanceOnlyWithFrom(): void
    {
        $opening = new StatementLedgerRow(LedgerRowKind::Opening, null, '', 'Saldo anterior', null, null, 500.0);

        $with = $this->builder->build($this->data([$opening, $this->charge()], from: '2026-03-01'));
        $without = $this->builder->build($this->data([$this->charge()]));

        $this->assertStringContainsString('<tr class="opening">', $with);
        $this->assertSame(2, substr_count($with, 'Saldo anterior'), 'ledger row and summary line');
        $this->assertStringNotContainsString('Saldo anterior', $without);
        $this->assertStringContainsString('Desde 01/03/2026', $with);
        $this->assertStringContainsString('Todos los movimientos', $without);
    }

    public function testEmptyPeriodShowsTheEmptyRowAndTheBalance(): void
    {
        $html = $this->builder->build($this->data([], balance: 0.0, words: null, hasMovements: false));

        $this->assertStringContainsString('Sin movimientos en el periodo', $html);
        $this->assertStringContainsString('SALDO FINAL', $html);
        $this->assertStringContainsString('$0.00', $html);
    }

    public function testEmptyRowIsNotShownWithMovements(): void
    {
        $this->assertStringNotContainsString('Sin movimientos en el periodo', $this->builder->build($this->data([$this->charge()])));
    }

    // ---- summary and legend ----------------------------------------------

    public function testSummaryShowsTheTotals(): void
    {
        $html = $this->builder->build($this->data([$this->charge()]));

        $this->assertStringContainsString('Total de cargos', $html);
        $this->assertStringContainsString('$1,200.00', $html);
        $this->assertStringContainsString('Total de abonos', $html);
        $this->assertStringContainsString('$400.00', $html);
        $this->assertStringContainsString('SALDO FINAL', $html);
    }

    public function testPendingLegend(): void
    {
        $html = $this->builder->build($this->data([$this->charge()]));

        $this->assertStringContainsString('SALDO PENDIENTE', $html);
        $this->assertStringContainsString('OCHOCIENTOS PESOS 00/100 M.N.', $html);
    }

    public function testInFavorLegendShowsTheAbsoluteAmount(): void
    {
        $html = $this->builder->build($this->data([$this->payment()], balance: -300.0, words: 'TRESCIENTOS PESOS 00/100 M.N.'));

        $this->assertStringContainsString('SALDO A FAVOR', $html);
        $this->assertStringContainsString('<div>$300.00</div>', $html, 'the legend never shows a bare negative number');
        $this->assertStringContainsString('TRESCIENTOS PESOS 00/100 M.N.', $html);
    }

    public function testSettledLegendHasNoAmount(): void
    {
        $html = $this->builder->build($this->data([$this->charge()], balance: 0.0, words: null));

        $this->assertStringContainsString('SIN ADEUDO', $html);
        $this->assertStringNotContainsString('class="words"', $html);
    }

    // ---- header and client -----------------------------------------------

    public function testMissingClientFieldsRenderAsADash(): void
    {
        $html = $this->builder->build($this->data([$this->charge()]));

        $this->assertStringContainsString('<td class="k">Teléfono</td><td>—</td>', $html);
        $this->assertStringContainsString('maria@example.com', $html);
    }

    public function testLogoIsOnlyEmbeddedAsDataUri(): void
    {
        $uri = 'data:image/png;base64,iVBORw0KGgo=';

        $this->assertStringContainsString('<img src="' . $uri . '"', $this->builder->build($this->data([$this->charge()], logo: $uri)));
        $this->assertStringNotContainsString('<img', $this->builder->build($this->data([$this->charge()])));
    }

    public function testTitleIssueDateAndNotaryHeader(): void
    {
        $html = $this->builder->build($this->data([$this->charge()]));

        $this->assertStringContainsString('ESTADO DE CUENTA', $html);
        $this->assertStringContainsString('Fecha de emisión: 31/03/2026', $html);
        $this->assertStringContainsString('Notaría 42', $html);
        $this->assertStringContainsString('Av. Central 100, Querétaro', $html);
    }

    public function testHeaderHasNoLabels(): void
    {
        $html = $this->builder->build($this->data([$this->charge()]));

        $this->assertStringNotContainsString('Notario titular:', $html);
        $this->assertStringNotContainsString('RFC:', $html);
    }

    public function testHeaderShowsNotaryNameNotaryAndRfcWithoutLabels(): void
    {
        $html = $this->builder->build($this->data([$this->charge()]));

        $this->assertStringContainsString('<div class="notary-name">Notaría 42</div>', $html);
        $this->assertStringContainsString('<div>Lic. Juan Pérez</div>', $html);
        $this->assertStringContainsString('<div class="muted">XAXX010101000</div>', $html);
    }

    public function testEmptyRfcRendersTripleDash(): void
    {
        $html = $this->builder->build($this->data([$this->charge()], rfc: ''));

        $this->assertStringContainsString('<div class="muted">---</div>', $html);
    }

    public function testBlankRfcRendersTripleDash(): void
    {
        $html = $this->builder->build($this->data([$this->charge()], rfc: '   '));

        $this->assertStringContainsString('<div class="muted">---</div>', $html);
    }

    public function testEmptyNotaryNameKeepsTheEmDash(): void
    {
        $html = $this->builder->build($this->data([$this->charge()], titularName: ''));

        $this->assertStringContainsString('<div>—</div>', $html);
    }

    public function testHeaderLinesAreInOrder(): void
    {
        $html = $this->builder->build($this->data([$this->charge()]));

        $positions = array_map(
            static fn (string $needle): int|false => strpos($html, $needle),
            [
                '<div class="notary-name">Notaría 42</div>',
                '<div>Lic. Juan Pérez</div>',
                '<div class="muted">XAXX010101000</div>',
                '<div class="muted">Av. Central 100, Querétaro</div>',
                '<div class="muted">Tel. 4421234567</div>',
            ],
        );

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'name < notary < RFC < address < phone');
    }

    public function testEmptyRfcDoesNotChangeTheDashElsewhere(): void
    {
        $html = $this->builder->build($this->data([$this->charge()], rfc: ''));

        $this->assertStringContainsString('<td class="k">Teléfono</td><td>—</td>', $html);
    }

    public function testRfcAndNotaryNameAreEscaped(): void
    {
        $html = $this->builder->build($this->data(
            [$this->charge()],
            titularName: '<script>alert(1)</script>',
            rfc: '<script>alert(2)</script>',
        ));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<div>&lt;script&gt;alert(1)&lt;/script&gt;</div>', $html);
        $this->assertStringContainsString('<div class="muted">&lt;script&gt;alert(2)&lt;/script&gt;</div>', $html);
    }

    public function testStatementWithoutMovementsHasTheSameHeader(): void
    {
        $html = $this->builder->build($this->data([], balance: 0.0, words: null, hasMovements: false, rfc: ''));

        $this->assertStringContainsString('Sin movimientos en el periodo', $html);
        $this->assertStringContainsString('<div>Lic. Juan Pérez</div>', $html);
        $this->assertStringContainsString('<div class="muted">---</div>', $html);
        $this->assertStringNotContainsString('Notario titular:', $html);
        $this->assertStringNotContainsString('RFC:', $html);
    }

    public function testTableHeaderRepeatsAndRowsDoNotSplit(): void
    {
        $html = $this->builder->build($this->data([$this->charge()]));

        $this->assertStringContainsString('display: table-header-group', $html);
        $this->assertStringContainsString('page-break-inside: avoid', $html);
    }
}
