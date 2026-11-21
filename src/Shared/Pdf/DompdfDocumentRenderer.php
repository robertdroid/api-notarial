<?php

declare(strict_types=1);

namespace App\Shared\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * HTML to the binary PDF, entirely in memory, with the safe Dompdf configuration shared by every
 * document. Any failure becomes a PdfRenderException so the caller never sends a partial PDF.
 *
 * Not final only so tests can replace the Dompdf instance (see createDompdf()).
 */
class DompdfDocumentRenderer
{
    private const FONT = 'DejaVu Sans';

    /** @param string $chroot Folder Dompdf may read from. The HTML only embeds data URIs, so keep it harmless. */
    public function __construct(private readonly string $chroot)
    {
    }

    public function render(string $html, \DateTimeImmutable $generatedAt): string
    {
        // Nothing Dompdf or a library prints may end up inside the response body
        $obLevel = ob_get_level();
        ob_start();

        try {
            $dompdf = $this->createDompdf($this->options());
            $dompdf->setPaper('letter', 'portrait');
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->render();
            $this->addFooter($dompdf, $generatedAt);

            $pdf = $dompdf->output();
        } catch (\Throwable $e) {
            throw new PdfRenderException('Could not render PDF: ' . $e->getMessage(), 0, $e);
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
        }

        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) {
            throw new PdfRenderException('Renderer produced an invalid PDF');
        }

        return $pdf;
    }

    protected function createDompdf(Options $options): Dompdf
    {
        return new Dompdf($options);
    }

    private function options(): Options
    {
        $fontCache = sys_get_temp_dir() . '/dompdf-fonts';
        if (!is_dir($fontCache)) {
            @mkdir($fontCache, 0775, true);
        }

        $options = new Options();
        // The HTML only embeds data URIs: no remote access and no PHP inside the HTML
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultFont', self::FONT);
        $options->set('tempDir', sys_get_temp_dir());
        $options->set('fontCache', $fontCache);
        $options->set('chroot', $this->chroot);

        return $options;
    }

    /** Page numbers are known only after layout, so the footer is drawn per page once rendered. */
    private function addFooter(Dompdf $dompdf, \DateTimeImmutable $generatedAt): void
    {
        $text = sprintf('Generado el %s by notarial.devrob.net - Pagina ', $generatedAt->format('d/m/Y H:i'));
        $font = $dompdf->getFontMetrics()->getFont(self::FONT);

        $dompdf->getCanvas()->page_script(
            static function (int $pageNumber, int $pageCount, $canvas, $fontMetrics) use ($text, $font): void {
                $line = $text . $pageNumber;
                $size = 8;
                $width = $fontMetrics->getTextWidth($line, $font, $size);

                // A watermark would leave its opacity set on the canvas: reset it or the footer fades out
                $canvas->set_opacity(1.0);
                $canvas->text(($canvas->get_width() - $width) / 2, $canvas->get_height() - 28, $line, $font, $size, [0.4, 0.4, 0.4]);
            }
        );
    }
}
