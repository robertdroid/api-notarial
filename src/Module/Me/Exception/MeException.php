<?php

declare(strict_types=1);

namespace App\Module\Me\Exception;

final class MeException extends \DomainException
{
    public function __construct(public readonly MeError $error)
    {
        parent::__construct($error->message());
    }
}
