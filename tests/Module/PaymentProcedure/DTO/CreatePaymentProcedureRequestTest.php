<?php

declare(strict_types=1);

namespace Tests\Module\PaymentProcedure\DTO;

use App\Module\PaymentProcedure\DTO\CreatePaymentProcedureRequest;
use PHPUnit\Framework\TestCase;

final class CreatePaymentProcedureRequestTest extends TestCase
{
    public function testValidRequest(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray(['procedureName' => 'Escritura', 'status' => 2]);

        $this->assertTrue($request->isValid());
        $this->assertSame('Escritura', $request->procedureName);
        $this->assertSame(2, $request->status);
    }

    public function testStatusDefaultsToOne(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray(['procedureName' => 'Escritura']);

        $this->assertTrue($request->isValid());
        $this->assertSame(1, $request->status);
    }

    public function testMissingNameIsInvalid(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray([]);

        $this->assertFalse($request->isValid());
        $this->assertSame('Procedure name is required', $request->getErrors()['procedureName']);
    }

    public function testEmptyNameIsInvalid(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray(['procedureName' => '']);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('procedureName', $request->getErrors());
    }

    public function testWhitespaceOnlyNameIsInvalid(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray(['procedureName' => "   \t "]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('procedureName', $request->getErrors());
    }

    public function testNameOf500CharactersIsValid(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray(['procedureName' => str_repeat('a', 500)]);

        $this->assertTrue($request->isValid());
    }

    public function testNameOf501CharactersIsInvalid(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray(['procedureName' => str_repeat('a', 501)]);

        $this->assertFalse($request->isValid());
        $this->assertSame('Procedure name must not exceed 500 characters', $request->getErrors()['procedureName']);
    }

    public function testMultibyteNameIsCountedByCharacters(): void
    {
        // "Subdivisión" is 11 characters but 12 bytes.
        $valid = CreatePaymentProcedureRequest::fromArray(['procedureName' => str_repeat('ó', 500)]);
        $invalid = CreatePaymentProcedureRequest::fromArray(['procedureName' => str_repeat('ó', 501)]);

        $this->assertTrue($valid->isValid());
        $this->assertFalse($invalid->isValid());
    }

    public function testEmojiIsRejected(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray(['procedureName' => 'Escritura 😀']);

        $this->assertFalse($request->isValid());
        $this->assertSame('Procedure name contains unsupported characters', $request->getErrors()['procedureName']);
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray(['procedureName' => "Escritura \xC3\x28"]);

        $this->assertFalse($request->isValid());
        $this->assertSame('Procedure name contains unsupported characters', $request->getErrors()['procedureName']);
    }

    public function testNonStringNameIsInvalid(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray(['procedureName' => 123]);

        $this->assertFalse($request->isValid());
        $this->assertSame('Procedure name must be a string', $request->getErrors()['procedureName']);
    }

    public function testSpecialCharactersAreAllowed(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray(['procedureName' => 'Escritura (10%)']);

        $this->assertTrue($request->isValid());
    }

    public function testStatusThreeIsInvalid(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray(['procedureName' => 'Escritura', 'status' => 3]);

        $this->assertFalse($request->isValid());
        $this->assertSame('Status must be 1 or 2', $request->getErrors()['status']);
    }

    public function testStringStatusIsInvalid(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray(['procedureName' => 'Escritura', 'status' => '1']);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('status', $request->getErrors());
    }

    public function testNullStatusIsInvalid(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray(['procedureName' => 'Escritura', 'status' => null]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('status', $request->getErrors());
    }

    public function testNameIsTrimmed(): void
    {
        $request = CreatePaymentProcedureRequest::fromArray(['procedureName' => '  Escritura  ']);

        $this->assertTrue($request->isValid());
        $this->assertSame('Escritura', $request->procedureName);
    }
}
