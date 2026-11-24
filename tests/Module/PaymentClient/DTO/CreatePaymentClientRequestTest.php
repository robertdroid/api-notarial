<?php

declare(strict_types=1);

namespace Tests\Module\PaymentClient\DTO;

use App\Module\PaymentClient\DTO\CreatePaymentClientRequest;
use PHPUnit\Framework\TestCase;

final class CreatePaymentClientRequestTest extends TestCase
{
    public function testOnlyFullNameAppliesDefaults(): void
    {
        $dto = CreatePaymentClientRequest::fromArray(['fullName' => '  Juan Pérez ']);

        $this->assertTrue($dto->isValid());
        $this->assertSame('Juan Pérez', $dto->fullName);
        $this->assertSame('', $dto->phone);
        $this->assertSame('', $dto->email);
        $this->assertSame(1, $dto->status);
    }

    public function testAllFieldsAreAccepted(): void
    {
        $dto = CreatePaymentClientRequest::fromArray([
            'fullName' => 'Juan',
            'phone' => ' 555 ',
            'email' => ' juan@example.com ',
            'status' => 2,
        ]);

        $this->assertTrue($dto->isValid());
        $this->assertSame('555', $dto->phone);
        $this->assertSame('juan@example.com', $dto->email);
        $this->assertSame(2, $dto->status);
    }

    public function testNullPhoneAndEmailBecomeEmptyStrings(): void
    {
        $dto = CreatePaymentClientRequest::fromArray(['fullName' => 'Juan', 'phone' => null, 'email' => null]);

        $this->assertTrue($dto->isValid());
        $this->assertSame('', $dto->phone);
        $this->assertSame('', $dto->email);
    }

    public function testMissingFullNameIsRequired(): void
    {
        $dto = CreatePaymentClientRequest::fromArray([]);

        $this->assertFalse($dto->isValid());
        $this->assertSame('Full name is required', $dto->getErrors()['fullName']);
    }

    public function testNullFullNameIsRejectedAsNotString(): void
    {
        $dto = CreatePaymentClientRequest::fromArray(['fullName' => null]);

        $this->assertSame('Full name must be a string', $dto->getErrors()['fullName']);
    }

    public function testBlankFullNameIsRequired(): void
    {
        $dto = CreatePaymentClientRequest::fromArray(['fullName' => '   ']);

        $this->assertSame('Full name is required', $dto->getErrors()['fullName']);
    }

    public function testMultipleErrorsAreAccumulated(): void
    {
        $dto = CreatePaymentClientRequest::fromArray([
            'fullName' => '',
            'phone' => str_repeat('1', 21),
            'email' => 'nope',
            'status' => '1',
        ]);

        $this->assertFalse($dto->isValid());
        $this->assertSame(['fullName', 'phone', 'email', 'status'], array_keys($dto->getErrors()));
    }

    public function testWrongJsonTypesDoNotThrow(): void
    {
        $dto = CreatePaymentClientRequest::fromArray([
            'fullName' => ['x'],
            'phone' => 5551234,
            'email' => true,
            'status' => 'active',
        ]);

        $this->assertFalse($dto->isValid());
        $this->assertCount(4, $dto->getErrors());
    }

    public function testNotaryIdInBodyIsIgnored(): void
    {
        $dto = CreatePaymentClientRequest::fromArray(['fullName' => 'Juan', 'notaryId' => 99]);

        $this->assertTrue($dto->isValid());
        $this->assertFalse(property_exists($dto, 'notaryId'));
    }
}
