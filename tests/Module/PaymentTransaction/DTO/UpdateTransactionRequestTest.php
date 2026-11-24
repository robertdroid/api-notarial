<?php

declare(strict_types=1);

namespace Tests\Module\PaymentTransaction\DTO;

use App\Module\PaymentTransaction\DTO\UpdateTransactionRequest;
use PHPUnit\Framework\TestCase;

final class UpdateTransactionRequestTest extends TestCase
{
    public function testClientIdAndUuidAreOptional(): void
    {
        $dto = UpdateTransactionRequest::fromArray(['procedureId' => 2, 'date' => '2026-11-11']);

        $this->assertTrue($dto->isValid());
        $this->assertNull($dto->clientId);
        $this->assertNull($dto->uuid);
    }

    public function testFullReplacementDefaults(): void
    {
        $dto = UpdateTransactionRequest::fromArray(['procedureId' => 2, 'date' => '2026-11-11']);

        $this->assertSame(0.0, $dto->fee);
        $this->assertSame(0.0, $dto->taxes);
        $this->assertSame('', $dto->reference);
        $this->assertSame('', $dto->responsible);
        $this->assertSame('', $dto->comments);
        $this->assertSame([], $dto->details);
    }

    public function testPresentClientIdAndUuidAreKept(): void
    {
        $dto = UpdateTransactionRequest::fromArray([
            'clientId' => 5, 'uuid' => 'AbC', 'procedureId' => 2, 'date' => '2026-11-11',
        ]);

        $this->assertSame(5, $dto->clientId);
        $this->assertSame('AbC', $dto->uuid);
    }

    public function testPresentButInvalidClientIdAndUuidAreReported(): void
    {
        $dto = UpdateTransactionRequest::fromArray([
            'clientId' => 'x', 'uuid' => '', 'procedureId' => 2, 'date' => '2026-11-11',
        ]);

        $this->assertFalse($dto->isValid());
        $this->assertSame('Client ID must be a positive integer', $dto->getErrors()['clientId']);
        $this->assertArrayHasKey('uuid', $dto->getErrors());
    }

    public function testProcedureIdAndDateAreRequired(): void
    {
        $dto = UpdateTransactionRequest::fromArray([]);

        $this->assertSame([
            'procedureId' => 'Procedure ID is required',
            'date' => 'Date is required',
        ], $dto->getErrors());
    }
}
