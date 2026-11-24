<?php

declare(strict_types=1);

namespace Tests\Module\PaymentTransaction\DTO;

use App\Module\PaymentTransaction\DTO\CreateTransactionRequest;
use PHPUnit\Framework\TestCase;

final class CreateTransactionRequestTest extends TestCase
{
    /** @return array<string, mixed> */
    private function minimal(): array
    {
        return ['clientId' => 5, 'procedureId' => 2, 'uuid' => 'AbC123', 'date' => '2026-11-11'];
    }

    public function testMinimalValidBodyUsesDefaults(): void
    {
        $dto = CreateTransactionRequest::fromArray($this->minimal());

        $this->assertTrue($dto->isValid());
        $this->assertSame(5, $dto->clientId);
        $this->assertSame(2, $dto->procedureId);
        $this->assertSame('AbC123', $dto->uuid);
        $this->assertSame(0.0, $dto->fee);
        $this->assertSame(0.0, $dto->taxes);
        $this->assertSame('', $dto->reference);
        $this->assertSame([], $dto->details);
    }

    public function testCompleteBodyIsParsedAndTextIsTrimmed(): void
    {
        $dto = CreateTransactionRequest::fromArray($this->minimal() + [
            'fee' => 100.456, 'taxes' => 10, 'reference' => ' R ', 'responsible' => ' Luis ', 'comments' => ' c ',
            'details' => [['taxId' => 1, 'quantity' => 2, 'amount' => 5]],
        ]);

        $this->assertTrue($dto->isValid());
        $this->assertSame(100.46, $dto->fee);
        $this->assertSame(10.0, $dto->taxes);
        $this->assertSame('R', $dto->reference);
        $this->assertSame('Luis', $dto->responsible);
        $this->assertSame('c', $dto->comments);
        $this->assertCount(1, $dto->details);
    }

    public function testNullDataReportsEveryRequiredField(): void
    {
        $dto = CreateTransactionRequest::fromArray(null);

        $this->assertFalse($dto->isValid());
        $this->assertSame([
            'clientId' => 'Client ID is required',
            'uuid' => 'UUID is required',
            'procedureId' => 'Procedure ID is required',
            'date' => 'Date is required',
        ], $dto->getErrors());
    }

    public function testSeveralErrorsAreReportedAtOnce(): void
    {
        $dto = CreateTransactionRequest::fromArray([
            'clientId' => '5', 'procedureId' => 0, 'uuid' => 'bad uuid', 'date' => '2024-02-30',
            'fee' => -1, 'taxes' => 'x', 'reference' => 1, 'details' => 'nope',
        ]);

        $this->assertSame([
            'clientId', 'uuid', 'procedureId', 'date', 'fee', 'taxes', 'reference', 'details',
        ], array_keys($dto->getErrors()));
        $this->assertSame('Fee cannot be negative', $dto->getErrors()['fee']);
        $this->assertSame('Taxes must be a number', $dto->getErrors()['taxes']);
        $this->assertSame('Reference must be a string', $dto->getErrors()['reference']);
    }

    public function testExplicitNullMoneyIsRejected(): void
    {
        $dto = CreateTransactionRequest::fromArray($this->minimal() + ['fee' => null]);

        $this->assertSame(['fee' => 'Fee must be a number'], $dto->getErrors());
    }

    public function testNullTextMeansEmpty(): void
    {
        $dto = CreateTransactionRequest::fromArray($this->minimal() + ['comments' => null]);

        $this->assertTrue($dto->isValid());
        $this->assertSame('', $dto->comments);
    }

    public function testUuidIsNotTrimmedNorLowercased(): void
    {
        $dto = CreateTransactionRequest::fromArray(['uuid' => ' AbC'] + $this->minimal());

        $this->assertArrayHasKey('uuid', $dto->getErrors());
        $this->assertSame('AbC123', CreateTransactionRequest::fromArray($this->minimal())->uuid);
    }
}
