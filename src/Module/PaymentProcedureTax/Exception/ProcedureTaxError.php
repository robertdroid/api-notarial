<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedureTax\Exception;

enum ProcedureTaxError: string
{
    case ProcedureNotFound = 'procedure_not_found';
    case ProcedureInactive = 'procedure_inactive';
    case TaxNotFound = 'tax_not_found';
    case TaxInactive = 'tax_inactive';
    case AlreadyAssigned = 'already_assigned';
    case NotAssigned = 'not_assigned';

    public function message(): string
    {
        return match ($this) {
            self::ProcedureNotFound => 'Procedure not found',
            self::ProcedureInactive => 'Procedure is inactive',
            self::TaxNotFound => 'Tax not found',
            self::TaxInactive => 'Tax is inactive',
            self::AlreadyAssigned => 'Tax is already assigned to this procedure',
            self::NotAssigned => 'Tax is not assigned to this procedure',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::ProcedureNotFound, self::TaxNotFound, self::NotAssigned => 404,
            self::AlreadyAssigned => 409,
            self::ProcedureInactive, self::TaxInactive => 422,
        };
    }
}
