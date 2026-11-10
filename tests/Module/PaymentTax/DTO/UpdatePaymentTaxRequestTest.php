<?php

declare(strict_types=1);

namespace Tests\Module\PaymentTax\DTO;

use App\Module\PaymentTax\DTO\UpdatePaymentTaxRequest;
use PHPUnit\Framework\TestCase;

final class UpdatePaymentTaxRequestTest extends TestCase
{
    public function testOnlyStatusIsValid(): void
    {
        $request = UpdatePaymentTaxRequest::fromArray(['status' => 2]);

        $this->assertTrue($request->isValid());
        $this->assertNull($request->taxName);
        $this->assertSame(2, $request->status);
    }

    public function testOnlyNameIsValidAndTrimmed(): void
    {
        $request = UpdatePaymentTaxRequest::fromArray(['taxName' => '  ISR  ']);

        $this->assertTrue($request->isValid());
        $this->assertSame('ISR', $request->taxName);
        $this->assertNull($request->status);
    }

    public function testBothFieldsAreValid(): void
    {
        $request = UpdatePaymentTaxRequest::fromArray(['taxName' => 'ISR', 'status' => 1]);

        $this->assertTrue($request->isValid());
        $this->assertSame('ISR', $request->taxName);
        $this->assertSame(1, $request->status);
    }

    public function testEmptyBodyIsInvalid(): void
    {
        $request = UpdatePaymentTaxRequest::fromArray([]);

        $this->assertFalse($request->isValid());
        $this->assertSame(
            'At least one field (taxName, status) is required',
            $request->getErrors()['body']
        );
    }

    public function testBodyWithUnknownFieldsOnlyIsInvalid(): void
    {
        $request = UpdatePaymentTaxRequest::fromArray(['foo' => 'bar']);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('body', $request->getErrors());
    }

    public function testNullNameIsInvalid(): void
    {
        $request = UpdatePaymentTaxRequest::fromArray(['taxName' => null]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('taxName', $request->getErrors());
        $this->assertArrayNotHasKey('body', $request->getErrors());
    }

    public function testEmptyNameIsInvalid(): void
    {
        $request = UpdatePaymentTaxRequest::fromArray(['taxName' => '']);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('taxName', $request->getErrors());
    }

    public function testEmojiNameIsInvalid(): void
    {
        $request = UpdatePaymentTaxRequest::fromArray(['taxName' => 'ISR 😀']);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('taxName', $request->getErrors());
    }

    public function testInvalidStatusIsInvalid(): void
    {
        $request = UpdatePaymentTaxRequest::fromArray(['status' => 3]);

        $this->assertFalse($request->isValid());
        $this->assertSame('Status must be 1 or 2', $request->getErrors()['status']);
    }

    public function testStringStatusIsInvalid(): void
    {
        $request = UpdatePaymentTaxRequest::fromArray(['status' => '2']);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('status', $request->getErrors());
    }
}
