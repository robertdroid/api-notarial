<?php

declare(strict_types=1);

namespace App\Module\Payment\Exception;

enum PaymentError: string
{
    case PaymentNotFound = 'payment_not_found';
    case ClientNotFound = 'client_not_found';
    case ClientInactive = 'client_inactive';
    case CannotChangeClient = 'cannot_change_client';
    case CannotChangeUuid = 'cannot_change_uuid';

    public function message(): string
    {
        return match ($this) {
            self::PaymentNotFound => 'Payment not found',
            self::ClientNotFound => 'Client not found',
            self::ClientInactive => 'Client is inactive',
            self::CannotChangeClient => 'Cannot change client',
            self::CannotChangeUuid => 'Cannot change UUID',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::PaymentNotFound, self::ClientNotFound => 404,
            self::CannotChangeClient, self::CannotChangeUuid => 400,
            self::ClientInactive => 422,
        };
    }
}
