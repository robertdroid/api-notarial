<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedureTax\Exception;

final class ProcedureTaxException extends \DomainException
{
    public function __construct(public readonly ProcedureTaxError $error)
    {
        parent::__construct($error->message());
    }
}
