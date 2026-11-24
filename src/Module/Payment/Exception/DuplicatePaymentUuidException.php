<?php

declare(strict_types=1);

namespace App\Module\Payment\Exception;

/**
 * Raised by the Repository when an INSERT collides with UNIQUE (id_notary, uuid).
 * Consumed by the Service to resolve concurrent POSTs with the same uuid.
 */
final class DuplicatePaymentUuidException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Payment uuid already exists', 0, $previous);
    }
}
