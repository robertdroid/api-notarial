<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedureTax\Exception;

final class InvalidProcedureTaxItemsException extends \DomainException
{
    /** @param array<int, ProcedureTaxError> $itemErrors item index => error */
    public function __construct(public readonly array $itemErrors)
    {
        parent::__construct('Invalid procedure tax items');
    }
}
