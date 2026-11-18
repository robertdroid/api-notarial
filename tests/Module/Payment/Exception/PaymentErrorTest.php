<?php

declare(strict_types=1);

namespace Tests\Module\Payment\Exception;

use App\Module\Payment\Exception\DuplicatePaymentUuidException;
use App\Module\Payment\Exception\PaymentError;
use App\Module\Payment\Exception\PaymentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentErrorTest extends TestCase
{
    /** @return array<string, array{PaymentError, string, int}> */
    public static function errors(): array
    {
        return [
            'payment not found' => [PaymentError::PaymentNotFound, 'Payment not found', 404],
            'client not found' => [PaymentError::ClientNotFound, 'Client not found', 404],
            'client inactive' => [PaymentError::ClientInactive, 'Client is inactive', 422],
            'cannot change client' => [PaymentError::CannotChangeClient, 'Cannot change client', 400],
            'cannot change uuid' => [PaymentError::CannotChangeUuid, 'Cannot change UUID', 400],
        ];
    }

    #[DataProvider('errors')]
    public function testMessageAndHttpStatus(PaymentError $error, string $message, int $status): void
    {
        $this->assertSame($message, $error->message());
        $this->assertSame($status, $error->httpStatus());
    }

    public function testExceptionCarriesTheErrorAndItsMessage(): void
    {
        $exception = new PaymentException(PaymentError::ClientInactive);

        $this->assertSame(PaymentError::ClientInactive, $exception->error);
        $this->assertSame('Client is inactive', $exception->getMessage());
    }

    public function testDuplicateUuidExceptionKeepsThePreviousError(): void
    {
        $previous = new \PDOException('Duplicate entry');
        $exception = new DuplicatePaymentUuidException($previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
