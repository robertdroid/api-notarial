<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\Renderer;

use App\Module\PaymentStatement\DTO\StatementPdfData;
use App\Module\PaymentStatement\Exception\StatementGenerationException;
use App\Shared\Pdf\DompdfDocumentRenderer;

/**
 * StatementPdfData to the binary PDF, entirely in memory. Any failure becomes a
 * StatementGenerationException so the caller never sends a partial PDF.
 */
final class StatementPdfRenderer
{
    /**
     * Dompdf needs about 0.25 MB per ledger row (spike, spec 21): 400 rows peak at ~100 MB, so with PHP's
     * default memory_limit of 128M the render starts to run out of memory a little above 500 rows.
     * Running out of memory is a fatal error, not an exception: this log line is the only trace left.
     */
    public const ROW_WARNING_THRESHOLD = 400;

    public function __construct(
        private readonly StatementHtmlBuilder $htmlBuilder,
        private readonly DompdfDocumentRenderer $documentRenderer,
    ) {
    }

    public function render(StatementPdfData $data): string
    {
        $rowCount = count($data->rows);
        if ($rowCount > self::ROW_WARNING_THRESHOLD) {
            error_log(sprintf(
                '[statement] client %d: %d ledger rows (> %d), the render may exceed memory_limit (%s)',
                $data->clientId,
                $rowCount,
                self::ROW_WARNING_THRESHOLD,
                ini_get('memory_limit')
            ));
        }

        try {
            return $this->documentRenderer->render($this->htmlBuilder->build($data), $data->generatedAt);
        } catch (\Throwable $e) {
            throw new StatementGenerationException('Could not render statement: ' . $e->getMessage(), 0, $e);
        }
    }
}
