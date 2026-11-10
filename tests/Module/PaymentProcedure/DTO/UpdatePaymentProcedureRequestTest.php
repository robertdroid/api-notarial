<?php

declare(strict_types=1);

namespace Tests\Module\PaymentProcedure\DTO;

use App\Module\PaymentProcedure\DTO\UpdatePaymentProcedureRequest;
use PHPUnit\Framework\TestCase;

final class UpdatePaymentProcedureRequestTest extends TestCase
{
    public function testOnlyStatusIsValid(): void
    {
        $request = UpdatePaymentProcedureRequest::fromArray(['status' => 2]);

        $this->assertTrue($request->isValid());
        $this->assertNull($request->procedureName);
        $this->assertSame(2, $request->status);
    }

    public function testOnlyNameIsValidAndTrimmed(): void
    {
        $request = UpdatePaymentProcedureRequest::fromArray(['procedureName' => '  Escritura  ']);

        $this->assertTrue($request->isValid());
        $this->assertSame('Escritura', $request->procedureName);
        $this->assertNull($request->status);
    }

    public function testBothFieldsAreValid(): void
    {
        $request = UpdatePaymentProcedureRequest::fromArray(['procedureName' => 'Escritura', 'status' => 1]);

        $this->assertTrue($request->isValid());
        $this->assertSame('Escritura', $request->procedureName);
        $this->assertSame(1, $request->status);
    }

    public function testEmptyBodyIsInvalid(): void
    {
        $request = UpdatePaymentProcedureRequest::fromArray([]);

        $this->assertFalse($request->isValid());
        $this->assertSame(
            'At least one field (procedureName, status) is required',
            $request->getErrors()['body']
        );
    }

    public function testBodyWithUnknownFieldsOnlyIsInvalid(): void
    {
        $request = UpdatePaymentProcedureRequest::fromArray(['foo' => 'bar']);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('body', $request->getErrors());
    }

    public function testNullNameIsInvalid(): void
    {
        $request = UpdatePaymentProcedureRequest::fromArray(['procedureName' => null]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('procedureName', $request->getErrors());
        $this->assertArrayNotHasKey('body', $request->getErrors());
    }

    public function testEmptyNameIsInvalid(): void
    {
        $request = UpdatePaymentProcedureRequest::fromArray(['procedureName' => '']);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('procedureName', $request->getErrors());
    }

    public function testEmojiNameIsInvalid(): void
    {
        $request = UpdatePaymentProcedureRequest::fromArray(['procedureName' => 'Escritura 😀']);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('procedureName', $request->getErrors());
    }

    public function testInvalidStatusIsInvalid(): void
    {
        $request = UpdatePaymentProcedureRequest::fromArray(['status' => 3]);

        $this->assertFalse($request->isValid());
        $this->assertSame('Status must be 1 or 2', $request->getErrors()['status']);
    }

    public function testStringStatusIsInvalid(): void
    {
        $request = UpdatePaymentProcedureRequest::fromArray(['status' => '2']);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('status', $request->getErrors());
    }
}
