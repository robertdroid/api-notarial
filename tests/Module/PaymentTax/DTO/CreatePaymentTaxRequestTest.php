<?php

declare(strict_types=1);

namespace Tests\Module\PaymentTax\DTO;

use App\Module\PaymentTax\DTO\CreatePaymentTaxRequest;
use PHPUnit\Framework\TestCase;

final class CreatePaymentTaxRequestTest extends TestCase
{
    public function testValidRequest(): void
    {
        $request = CreatePaymentTaxRequest::fromArray(['taxName' => 'ISR', 'status' => 2]);

        $this->assertTrue($request->isValid());
        $this->assertSame('ISR', $request->taxName);
        $this->assertSame(2, $request->status);
    }

    public function testStatusDefaultsToOne(): void
    {
        $request = CreatePaymentTaxRequest::fromArray(['taxName' => 'ISR']);

        $this->assertTrue($request->isValid());
        $this->assertSame(1, $request->status);
    }

    public function testMissingNameIsInvalid(): void
    {
        $request = CreatePaymentTaxRequest::fromArray([]);

        $this->assertFalse($request->isValid());
        $this->assertSame('Tax name is required', $request->getErrors()['taxName']);
    }

    public function testEmptyNameIsInvalid(): void
    {
        $request = CreatePaymentTaxRequest::fromArray(['taxName' => '']);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('taxName', $request->getErrors());
    }

    public function testWhitespaceOnlyNameIsInvalid(): void
    {
        $request = CreatePaymentTaxRequest::fromArray(['taxName' => "   \t "]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('taxName', $request->getErrors());
    }

    public function testNameOf500CharactersIsValid(): void
    {
        $request = CreatePaymentTaxRequest::fromArray(['taxName' => str_repeat('a', 500)]);

        $this->assertTrue($request->isValid());
    }

    public function testNameOf501CharactersIsInvalid(): void
    {
        $request = CreatePaymentTaxRequest::fromArray(['taxName' => str_repeat('a', 501)]);

        $this->assertFalse($request->isValid());
        $this->assertSame('Tax name must not exceed 500 characters', $request->getErrors()['taxName']);
    }

    public function testMultibyteNameIsCountedByCharacters(): void
    {
        // "Subdivisión" is 11 characters but 12 bytes.
        $valid = CreatePaymentTaxRequest::fromArray(['taxName' => str_repeat('ó', 500)]);
        $invalid = CreatePaymentTaxRequest::fromArray(['taxName' => str_repeat('ó', 501)]);

        $this->assertTrue($valid->isValid());
        $this->assertFalse($invalid->isValid());
    }

    public function testEmojiIsRejected(): void
    {
        $request = CreatePaymentTaxRequest::fromArray(['taxName' => 'ISR 😀']);

        $this->assertFalse($request->isValid());
        $this->assertSame('Tax name contains unsupported characters', $request->getErrors()['taxName']);
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $request = CreatePaymentTaxRequest::fromArray(['taxName' => "ISR \xC3\x28"]);

        $this->assertFalse($request->isValid());
        $this->assertSame('Tax name contains unsupported characters', $request->getErrors()['taxName']);
    }

    public function testNonStringNameIsInvalid(): void
    {
        $request = CreatePaymentTaxRequest::fromArray(['taxName' => 123]);

        $this->assertFalse($request->isValid());
        $this->assertSame('Tax name must be a string', $request->getErrors()['taxName']);
    }

    public function testSpecialCharactersAreAllowed(): void
    {
        $request = CreatePaymentTaxRequest::fromArray(['taxName' => 'ISR (10%)']);

        $this->assertTrue($request->isValid());
    }

    public function testStatusThreeIsInvalid(): void
    {
        $request = CreatePaymentTaxRequest::fromArray(['taxName' => 'ISR', 'status' => 3]);

        $this->assertFalse($request->isValid());
        $this->assertSame('Status must be 1 or 2', $request->getErrors()['status']);
    }

    public function testStringStatusIsInvalid(): void
    {
        $request = CreatePaymentTaxRequest::fromArray(['taxName' => 'ISR', 'status' => '1']);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('status', $request->getErrors());
    }

    public function testNullStatusIsInvalid(): void
    {
        $request = CreatePaymentTaxRequest::fromArray(['taxName' => 'ISR', 'status' => null]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('status', $request->getErrors());
    }

    public function testNameIsTrimmed(): void
    {
        $request = CreatePaymentTaxRequest::fromArray(['taxName' => '  ISR  ']);

        $this->assertTrue($request->isValid());
        $this->assertSame('ISR', $request->taxName);
    }
}
