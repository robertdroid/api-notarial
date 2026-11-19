<?php

declare(strict_types=1);

namespace App\Module\Receipt\Exception;

/** The receipt could not be built or rendered. The controller maps it to a 500 JSON error. */
final class ReceiptGenerationException extends \RuntimeException
{
}
