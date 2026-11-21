<?php

declare(strict_types=1);

namespace Tests\Shared\Pdf;

use App\Shared\Pdf\DompdfDocumentRenderer;
use App\Shared\Pdf\PdfRenderException;
use Dompdf\Dompdf;
use Dompdf\Options;
use PHPUnit\Framework\TestCase;

final class DompdfDocumentRendererTest extends TestCase
{
    private const HTML = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><p>Hola, acentos áéíóú ñ</p></body></html>';

    private function generatedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-31 10:00:00', new \DateTimeZone('America/Mexico_City'));
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page(?!s)\b#', $pdf);
    }

    public function testRendersAValidPdf(): void
    {
        $pdf = (new DompdfDocumentRenderer(sys_get_temp_dir()))->render(self::HTML, $this->generatedAt());

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringEndsWith('%%EOF', rtrim($pdf));
        $this->assertSame(1, $this->pageCount($pdf));
    }

    public function testLongHtmlSpansSeveralPages(): void
    {
        $html = '<html><body>' . str_repeat('<p>Una línea de relleno.</p>', 200) . '</body></html>';

        $pdf = (new DompdfDocumentRenderer(sys_get_temp_dir()))->render($html, $this->generatedAt());

        $this->assertGreaterThan(1, $this->pageCount($pdf));
    }

    public function testMaliciousMarkupDoesNotBreakRendering(): void
    {
        $html = '<html><body><script>alert(1)</script><img src="http://127.0.0.1/x.png"><?php echo 1; ?></body></html>';

        $pdf = (new DompdfDocumentRenderer(sys_get_temp_dir()))->render($html, $this->generatedAt());

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function testRenderFailureIsWrappedAndNeverLeaksOutput(): void
    {
        $renderer = new class (sys_get_temp_dir()) extends DompdfDocumentRenderer {
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
        $level = ob_get_level();

        try {
            $renderer->render(self::HTML, $this->generatedAt());
            $this->fail('Expected PdfRenderException');
        } catch (PdfRenderException $e) {
            $this->assertStringContainsString('layout exploded', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }

        $this->assertSame($level, ob_get_level(), 'output buffers must be restored');
    }

    public function testNestedOutputBuffersAreRestoredEvenWhenTheRenderOpensOne(): void
    {
        $renderer = new class (sys_get_temp_dir()) extends DompdfDocumentRenderer {
            protected function createDompdf(Options $options): Dompdf
            {
                return new class ($options) extends Dompdf {
                    public function render(): void
                    {
                        ob_start();
                        echo 'leaked from a nested buffer';

                        throw new \RuntimeException('boom');
                    }
                };
            }
        };
        $level = ob_get_level();

        try {
            $renderer->render(self::HTML, $this->generatedAt());
            $this->fail('Expected PdfRenderException');
        } catch (PdfRenderException) {
        }

        $this->assertSame($level, ob_get_level());
    }

    public function testAnInvalidPdfIsRejected(): void
    {
        $renderer = new class (sys_get_temp_dir()) extends DompdfDocumentRenderer {
            protected function createDompdf(Options $options): Dompdf
            {
                return new class ($options) extends Dompdf {
                    public function output($options = [])
                    {
                        return 'not a pdf';
                    }
                };
            }
        };

        $this->expectException(PdfRenderException::class);
        $this->expectExceptionMessage('invalid PDF');

        $renderer->render(self::HTML, $this->generatedAt());
    }

    public function testDompdfIsConfiguredSafely(): void
    {
        $renderer = new class (sys_get_temp_dir() . '/chroot-probe') extends DompdfDocumentRenderer {
            public ?Options $captured = null;

            protected function createDompdf(Options $options): Dompdf
            {
                $this->captured = $options;

                return parent::createDompdf($options);
            }
        };

        $renderer->render(self::HTML, $this->generatedAt());
        $captured = $renderer->captured;

        $this->assertNotNull($captured);
        $this->assertFalse($captured->getIsRemoteEnabled());
        $this->assertFalse($captured->getIsPhpEnabled());
        $this->assertTrue($captured->getIsFontSubsettingEnabled());
        $this->assertSame('DejaVu Sans', $captured->getDefaultFont());
        $this->assertContains(sys_get_temp_dir() . '/chroot-probe', $captured->getChroot());
    }
}
