<?php

declare(strict_types=1);

namespace App\Module\User\Exception;

final class UserException extends \DomainException
{
    public function __construct(public readonly UserError $error)
    {
        parent::__construct($error->message());
    }
}
