<?php

declare(strict_types=1);

namespace App\Module\Receipt\Renderer;

use App\Module\Receipt\DTO\ReceiptData;
use App\Module\Receipt\Exception\ReceiptGenerationException;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * ReceiptData to the binary PDF, entirely in memory. Any failure becomes a
 * ReceiptGenerationException so the caller never sends a partial PDF.
 */
class ReceiptPdfRenderer
{
    private const FONT = 'DejaVu Sans';

    public function __construct(
        private readonly ReceiptHtmlBuilder $htmlBuilder,
        private readonly QrCodeGenerator $qrGenerator,
    ) {
    }

    public function render(ReceiptData $data): string
    {
        // Nothing Dompdf or a library prints may end up inside the response body
        $obLevel = ob_get_level();
        ob_start();

        try {
            $html = $this->htmlBuilder->build($data, $this->qrGenerator->dataUri($data->uuid));
            $dompdf = new Dompdf($this->options());
            $dompdf->setPaper('letter', 'portrait');
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->render();
            $this->addFooter($dompdf, $data);

            $pdf = $dompdf->output();
        } catch (\Throwable $e) {
            throw new ReceiptGenerationException('Could not render receipt: ' . $e->getMessage(), 0, $e);
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
        }

        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) {
            throw new ReceiptGenerationException('Renderer produced an invalid PDF');
        }

        return $pdf;
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
        // Harmless folder: the receipt has no reason to read files from disk
        $options->set('chroot', __DIR__ . '/templates');

        return $options;
    }

    /** Page numbers are known only after layout, so the footer is drawn per page once rendered. */
    private function addFooter(Dompdf $dompdf, ReceiptData $data): void
    {
        $text = sprintf('Generado el %s by notarial.devrob.net - Pagina ', $data->generatedAt->format('d/m/Y H:i'));
        $font = $dompdf->getFontMetrics()->getFont(self::FONT);

        $dompdf->getCanvas()->page_script(
            static function (int $pageNumber, int $pageCount, $canvas, $fontMetrics) use ($text, $font): void {
                $line = $text . $pageNumber;
                $size = 8;
                $width = $fontMetrics->getTextWidth($line, $font, $size);

                // The CANCELADO watermark leaves its opacity set on the canvas: reset it or the footer fades out
                $canvas->set_opacity(1.0);
                $canvas->text(($canvas->get_width() - $width) / 2, $canvas->get_height() - 28, $line, $font, $size, [0.4, 0.4, 0.4]);
            }
        );
    }
}
