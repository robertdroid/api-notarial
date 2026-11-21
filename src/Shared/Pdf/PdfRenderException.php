<?php

declare(strict_types=1);

namespace App\Shared\Pdf;

/** The HTML could not be turned into a valid PDF. Each module wraps it in its own exception. */
final class PdfRenderException extends \RuntimeException
{
}
