<?php

declare(strict_types=1);

namespace Tests\Module\PaymentTransaction\Exception;

use App\Module\PaymentTransaction\Exception\TransactionError;
use App\Module\PaymentTransaction\Exception\TransactionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionErrorTest extends TestCase
{
    /** @return array<string, array{TransactionError, string, int}> */
    public static function errors(): array
    {
        return [
            'transaction not found' => [TransactionError::TransactionNotFound, 'Transaction not found', 404],
            'client not found' => [TransactionError::ClientNotFound, 'Client not found', 404],
            'client inactive' => [TransactionError::ClientInactive, 'Client is inactive', 422],
            'procedure not found' => [TransactionError::ProcedureNotFound, 'Procedure not found', 404],
            'procedure inactive' => [TransactionError::ProcedureInactive, 'Procedure is inactive', 422],
            'cannot change client' => [TransactionError::CannotChangeClient, 'Cannot change client', 400],
            'cannot change uuid' => [TransactionError::CannotChangeUuid, 'Cannot change UUID', 400],
        ];
    }

    #[DataProvider('errors')]
    public function testMessageAndHttpStatus(TransactionError $error, string $message, int $status): void
    {
        $this->assertSame($message, $error->message());
        $this->assertSame($status, $error->httpStatus());
    }

    public function testExceptionCarriesTheErrorAndItsMessage(): void
    {
        $exception = new TransactionException(TransactionError::ClientInactive);

        $this->assertSame(TransactionError::ClientInactive, $exception->error);
        $this->assertSame('Client is inactive', $exception->getMessage());
    }
}
