<?php

declare(strict_types=1);

namespace App\Module\User\Exception;

/**
 * Raised by the Repository when a write collides with UNIQUE (usuario).
 * Consumed by the Service to resolve concurrent requests with the same username.
 */
final class DuplicateUsernameException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Username already exists', 0, $previous);
    }
}
