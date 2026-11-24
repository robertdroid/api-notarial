<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\Exception;

final class TransactionException extends \DomainException
{
    public function __construct(public readonly TransactionError $error)
    {
        parent::__construct($error->message());
    }
}
