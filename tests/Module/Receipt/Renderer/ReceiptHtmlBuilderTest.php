<?php

declare(strict_types=1);

namespace Tests\Module\Receipt\Renderer;

use App\Module\Receipt\DTO\ReceiptClient;
use App\Module\Receipt\DTO\ReceiptData;
use App\Module\Receipt\DTO\ReceiptNotary;
use App\Module\Receipt\DTO\ReceiptTaxLine;
use App\Module\Receipt\Entity\ReceiptType;
use App\Module\Receipt\Renderer\ReceiptHtmlBuilder;
use PHPUnit\Framework\TestCase;

final class ReceiptHtmlBuilderTest extends TestCase
{
    private const QR = 'data:image/png;base64,QRDATA';
    private const XSS = '<script>alert(1)</script><img src=x onerror=alert(2)>';

    private ReceiptHtmlBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ReceiptHtmlBuilder();
    }

    // ---- helpers ---------------------------------------------------------

    private function notary(
        ?string $logo = null,
        string $name = 'Notaría 42',
        string $notaryName = 'Lic. Juan Pérez',
        string $rfc = 'XAXX010101000',
    ): ReceiptNotary {
        return new ReceiptNotary($name, $notaryName, $rfc, 'Av. Central 100', 'Querétaro', '4421234567', $logo);
    }

    /** @param ReceiptTaxLine[] $taxLines */
    private function transaction(
        array $taxLines = [],
        bool $cancelled = false,
        ?ReceiptNotary $notary = null,
        string $clientName = 'Iñaki Ángeles',
        string $reference = 'REF-1',
    ): ReceiptData {
        return new ReceiptData(
            type: ReceiptType::Transaction,
            id: 123,
            uuid: 'Us2Gvq',
            date: '2024-04-02',
            notary: $notary ?? $this->notary(),
            client: new ReceiptClient($clientName, '4429876543', 'inaki@example.com'),
            total: 1350.0,
            amountInWords: 'MIL TRESCIENTOS CINCUENTA PESOS 00/100 M.N.',
            cancelled: $cancelled,
            generatedAt: new \DateTimeImmutable('2024-04-02 10:00:00'),
            procedureName: 'Compraventa',
            reference: $reference,
            responsible: 'Ana López',
            fee: 1000.0,
            taxes: 350.0,
            taxLines: $taxLines,
        );
    }

    private function payment(?string $date = '2024-04-02', string $comments = 'Adelanto', string $clientName = 'Iñaki Ángeles'): ReceiptData
    {
        return new ReceiptData(
            type: ReceiptType::Payment,
            id: 7,
            uuid: 'Pay123',
            date: $date,
            notary: $this->notary(),
            client: new ReceiptClient($clientName, '', ''),
            total: 5000.5,
            amountInWords: 'CINCO MIL PESOS 50/100 M.N.',
            cancelled: false,
            generatedAt: new \DateTimeImmutable('2024-04-02 10:00:00'),
            comments: $comments,
        );
    }

    // ---- content ---------------------------------------------------------

    public function testTransactionReceiptShowsAllSections(): void
    {
        $html = $this->builder->build($this->transaction([new ReceiptTaxLine(2, 'ISAI', 350.0)]), self::QR);

        foreach ([
            'RECIBO DE TRÁMITE', 'T-000123', '02/04/2024', 'Notaría 42', 'Lic. Juan Pérez', 'XAXX010101000',
            'Av. Central 100, Querétaro', '4421234567', 'Iñaki Ángeles', '4429876543', 'inaki@example.com',
            'Compraventa', 'REF-1', 'Ana López', '$1,000.00', 'ISAI', '$350.00', '$1,350.00',
            'MIL TRESCIENTOS CINCUENTA PESOS 00/100 M.N.', self::QR,
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    public function testPaymentReceiptShowsPaymentSections(): void
    {
        $html = $this->builder->build($this->payment(), self::QR);

        $this->assertStringContainsString('RECIBO DE COBRO', $html);
        $this->assertStringContainsString('C-000007', $html);
        $this->assertStringContainsString('$5,000.50', $html);
        $this->assertStringContainsString('Adelanto', $html);
        $this->assertStringContainsString('CINCO MIL PESOS 50/100 M.N.', $html);
        $this->assertStringNotContainsString('Cuota de honorarios', $html);
        $this->assertStringNotContainsString('Procedimiento', $html);
    }

    public function testPaymentWithoutDateShowsSinFecha(): void
    {
        $html = $this->builder->build($this->payment(date: null, comments: ''), self::QR);

        $this->assertStringContainsString('Sin fecha', $html);
    }

    public function testNeverPrintsTheWordNullOrPhpNotices(): void
    {
        $html = $this->builder->build($this->payment(date: null, comments: '', clientName: ''), self::QR);

        $this->assertStringNotContainsStringIgnoringCase('null', $html);
        $this->assertStringNotContainsString('Warning', $html);
        $this->assertStringNotContainsString('Deprecated', $html);
        $this->assertStringNotContainsString('<?', $html);
    }

    public function testEmptyValuesRenderAsDash(): void
    {
        $html = $this->builder->build($this->transaction(clientName: '', reference: ''), self::QR);

        $this->assertStringContainsString('<td class="k">Nombre</td><td>—</td>', $html);
        $this->assertStringContainsString('<td class="k">Referencia</td><td>—</td>', $html);
    }

    // ---- notary header ---------------------------------------------------

    public function testHeaderHasNoLabels(): void
    {
        $html = $this->builder->build($this->transaction(), self::QR);

        $this->assertStringNotContainsString('Notario titular:', $html);
        $this->assertStringNotContainsString('RFC:', $html);
    }

    public function testHeaderShowsNotaryNameNotaryAndRfcWithoutLabels(): void
    {
        $html = $this->builder->build($this->transaction(), self::QR);

        $this->assertStringContainsString('<div class="notary-name">Notaría 42</div>', $html);
        $this->assertStringContainsString('<div>Lic. Juan Pérez</div>', $html);
        $this->assertStringContainsString('<div class="muted">XAXX010101000</div>', $html);
    }

    public function testEmptyRfcRendersTripleDash(): void
    {
        $html = $this->builder->build($this->transaction(notary: $this->notary(rfc: '')), self::QR);

        $this->assertStringContainsString('<div class="muted">---</div>', $html);
    }

    public function testBlankRfcRendersTripleDash(): void
    {
        $html = $this->builder->build($this->transaction(notary: $this->notary(rfc: '   ')), self::QR);

        $this->assertStringContainsString('<div class="muted">---</div>', $html);
    }

    public function testEmptyNotaryNameKeepsTheEmDash(): void
    {
        $html = $this->builder->build($this->transaction(notary: $this->notary(notaryName: '')), self::QR);

        $this->assertStringContainsString('<div>—</div>', $html);
    }

    public function testHeaderLinesAreInOrder(): void
    {
        $html = $this->builder->build($this->transaction(), self::QR);

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
        $html = $this->builder->build($this->transaction(notary: $this->notary(rfc: ''), clientName: '', reference: ''), self::QR);

        $this->assertStringContainsString('<td class="k">Nombre</td><td>—</td>', $html);
        $this->assertStringContainsString('<td class="k">Referencia</td><td>—</td>', $html);
    }

    public function testCancelledReceiptHasTheSameHeader(): void
    {
        $html = $this->builder->build($this->transaction(cancelled: true, notary: $this->notary(rfc: '')), self::QR);

        $this->assertStringContainsString('<div class="watermark">CANCELADO</div>', $html);
        $this->assertStringContainsString('<div>Lic. Juan Pérez</div>', $html);
        $this->assertStringContainsString('<div class="muted">---</div>', $html);
        $this->assertStringNotContainsString('Notario titular:', $html);
        $this->assertStringNotContainsString('RFC:', $html);
    }

    public function testOrDashUsesTheEmDashByDefaultAndAcceptsAPlaceholder(): void
    {
        $this->assertSame('—', ReceiptHtmlBuilder::orDash(null));
        $this->assertSame('—', ReceiptHtmlBuilder::orDash(''));
        $this->assertSame('—', ReceiptHtmlBuilder::orDash('  '));
        $this->assertSame('---', ReceiptHtmlBuilder::orDash(null, '---'));
        $this->assertSame('---', ReceiptHtmlBuilder::orDash('  ', '---'));
        $this->assertSame('A &amp; B', ReceiptHtmlBuilder::orDash('A & B', '---'));
    }

    // ---- tax table -------------------------------------------------------

    public function testTaxTableIsOmittedWithoutRows(): void
    {
        $html = $this->builder->build($this->transaction([]), self::QR);

        $this->assertStringNotContainsString('class="items"', $html);
        $this->assertStringNotContainsString('Cantidad', $html);
        $this->assertStringContainsString('Impuestos totales', $html);
    }

    public function testTaxTableRepeatsItsHeaderAcrossPages(): void
    {
        $html = $this->builder->build($this->transaction([new ReceiptTaxLine(1, 'ISAI', 100.0)]), self::QR);

        $this->assertStringContainsString('class="items"', $html);
        $this->assertStringContainsString('<th class="qty">Cantidad</th>', $html);
        $this->assertStringContainsString('.items thead { display: table-header-group; }', $html);
        $this->assertStringContainsString('page-break-inside: avoid', $html);
    }

    // ---- watermark and logo ----------------------------------------------

    public function testWatermarkOnlyWhenCancelled(): void
    {
        $this->assertStringContainsString('<div class="watermark">CANCELADO</div>', $this->builder->build($this->transaction(cancelled: true), self::QR));
        $this->assertStringNotContainsString('CANCELADO', $this->builder->build($this->transaction(cancelled: false), self::QR));
        $this->assertStringNotContainsString('CANCELADO', $this->builder->build($this->payment(), self::QR));
    }

    public function testLogoOnlyRenderedWhenPresent(): void
    {
        $logo = 'data:image/png;base64,TE9HTw==';

        $with = $this->builder->build($this->transaction(notary: $this->notary($logo)), self::QR);
        $without = $this->builder->build($this->transaction(), self::QR);

        $this->assertStringContainsString('<td class="logo"><img src="' . $logo . '"', $with);
        $this->assertStringNotContainsString('class="logo"><img', $without);
    }

    // ---- escaping --------------------------------------------------------

    public function testEveryUserValueIsEscaped(): void
    {
        $data = new ReceiptData(
            type: ReceiptType::Transaction,
            id: 1,
            uuid: 'abc',
            date: '2024-04-02',
            notary: new ReceiptNotary(self::XSS, self::XSS, self::XSS, self::XSS, self::XSS, self::XSS, null),
            client: new ReceiptClient(self::XSS, self::XSS, self::XSS),
            total: 10.0,
            amountInWords: self::XSS,
            cancelled: false,
            generatedAt: new \DateTimeImmutable('2024-04-02 10:00:00'),
            procedureName: self::XSS,
            reference: self::XSS,
            responsible: self::XSS,
            fee: 5.0,
            taxes: 5.0,
            taxLines: [new ReceiptTaxLine(1, self::XSS, 5.0)],
            comments: self::XSS,
        );

        $html = $this->builder->build($data, self::QR);

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testPaymentCommentsAreEscaped(): void
    {
        $html = $this->builder->build($this->payment(comments: self::XSS), self::QR);

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAttributeBreakoutInLogoIsEscaped(): void
    {
        $logo = 'data:image/png;base64,AAAA" onerror="alert(1)';

        $html = $this->builder->build($this->transaction(notary: $this->notary($logo)), self::QR);

        $this->assertStringNotContainsString('" onerror="', $html);
    }

    public function testInvalidUtf8IsSubstitutedNotDropped(): void
    {
        $html = $this->builder->build($this->transaction(clientName: "Mar\xC3\x28a"), self::QR);

        $this->assertStringContainsString('Mar', $html);
        $this->assertStringContainsString('a</td>', $html);
    }
}
