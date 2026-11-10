<?php

declare(strict_types=1);

namespace Tests\Module\PaymentProcedureTax\Exception;

use App\Module\PaymentProcedureTax\Exception\InvalidProcedureTaxItemsException;
use App\Module\PaymentProcedureTax\Exception\ProcedureTaxError;
use App\Module\PaymentProcedureTax\Exception\ProcedureTaxException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcedureTaxErrorTest extends TestCase
{
    #[DataProvider('catalog')]
    public function testMessageAndHttpStatus(ProcedureTaxError $error, string $message, int $status): void
    {
        $this->assertSame($message, $error->message());
        $this->assertSame($status, $error->httpStatus());
    }

    /** @return array<string, array{ProcedureTaxError, string, int}> */
    public static function catalog(): array
    {
        return [
            'procedure not found' => [ProcedureTaxError::ProcedureNotFound, 'Procedure not found', 404],
            'procedure inactive' => [ProcedureTaxError::ProcedureInactive, 'Procedure is inactive', 422],
            'tax not found' => [ProcedureTaxError::TaxNotFound, 'Tax not found', 404],
            'tax inactive' => [ProcedureTaxError::TaxInactive, 'Tax is inactive', 422],
            'already assigned' => [
                ProcedureTaxError::AlreadyAssigned,
                'Tax is already assigned to this procedure',
                409,
            ],
            'not assigned' => [ProcedureTaxError::NotAssigned, 'Tax is not assigned to this procedure', 404],
        ];
    }

    public function testExceptionsAreDomainExceptions(): void
    {
        $single = new ProcedureTaxException(ProcedureTaxError::TaxInactive);
        $bulk = new InvalidProcedureTaxItemsException([1 => ProcedureTaxError::TaxNotFound]);

        $this->assertInstanceOf(\DomainException::class, $single);
        $this->assertSame(ProcedureTaxError::TaxInactive, $single->error);
        $this->assertInstanceOf(\DomainException::class, $bulk);
        $this->assertSame([1 => ProcedureTaxError::TaxNotFound], $bulk->itemErrors);
    }
}
