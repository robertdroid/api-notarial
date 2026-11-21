<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\Exception;

/** The statement PDF could not be built or rendered. The controller maps it to a 500 JSON error. */
final class StatementGenerationException extends \RuntimeException
{
}
