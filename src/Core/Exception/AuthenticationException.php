<?php

declare(strict_types=1);

namespace App\Core\Exception;

class AuthenticationException extends HttpException
{
    public function __construct(string $message = 'Credenciales inválidas')
    {
        parent::__construct($message, 401);
    }
}
