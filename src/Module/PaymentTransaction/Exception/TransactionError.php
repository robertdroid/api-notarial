<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\Exception;

enum TransactionError: string
{
    case TransactionNotFound = 'transaction_not_found';
    case ClientNotFound = 'client_not_found';
    case ClientInactive = 'client_inactive';
    case ProcedureNotFound = 'procedure_not_found';
    case ProcedureInactive = 'procedure_inactive';
    case CannotChangeClient = 'cannot_change_client';
    case CannotChangeUuid = 'cannot_change_uuid';

    public function message(): string
    {
        return match ($this) {
            self::TransactionNotFound => 'Transaction not found',
            self::ClientNotFound => 'Client not found',
            self::ClientInactive => 'Client is inactive',
            self::ProcedureNotFound => 'Procedure not found',
            self::ProcedureInactive => 'Procedure is inactive',
            self::CannotChangeClient => 'Cannot change client',
            self::CannotChangeUuid => 'Cannot change UUID',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::TransactionNotFound, self::ClientNotFound, self::ProcedureNotFound => 404,
            self::CannotChangeClient, self::CannotChangeUuid => 400,
            self::ClientInactive, self::ProcedureInactive => 422,
        };
    }
}
