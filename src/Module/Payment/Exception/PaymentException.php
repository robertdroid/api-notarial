<?php

declare(strict_types=1);

namespace App\Module\Payment\Exception;

final class PaymentException extends \DomainException
{
    public function __construct(public readonly PaymentError $error)
    {
        parent::__construct($error->message());
    }
}
