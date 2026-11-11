<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\Exception;

/**
 * Raised by the Repository when an INSERT collides with UNIQUE (id_notary, uuid).
 * Consumed by the Service to resolve concurrent POSTs with the same uuid.
 */
final class DuplicateTransactionUuidException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Transaction uuid already exists', 0, $previous);
    }
}
