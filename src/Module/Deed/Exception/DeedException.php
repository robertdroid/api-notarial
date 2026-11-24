<?php

declare(strict_types=1);

namespace App\Module\Deed\Exception;

final class DeedException extends \DomainException
{
    /** @param array<string, int> $details Extra data for the frontend (e.g. the registration limit) */
    public function __construct(
        public readonly DeedError $error,
        public readonly array $details = [],
    ) {
        parent::__construct($error->message($details));
    }
}
