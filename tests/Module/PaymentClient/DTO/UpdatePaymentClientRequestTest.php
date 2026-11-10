<?php

declare(strict_types=1);

namespace Tests\Module\PaymentClient\DTO;

use App\Module\PaymentClient\DTO\UpdatePaymentClientRequest;
use PHPUnit\Framework\TestCase;

final class UpdatePaymentClientRequestTest extends TestCase
{
    public function testEmptyBodyIsRejected(): void
    {
        $dto = UpdatePaymentClientRequest::fromArray([]);

        $this->assertFalse($dto->isValid());
        $this->assertSame(
            'At least one field (fullName, phone, email, status) is required',
            $dto->getErrors()['body']
        );
    }

    public function testBodyWithOnlyUnknownFieldsIsRejected(): void
    {
        $dto = UpdatePaymentClientRequest::fromArray(['notaryId' => 5]);

        $this->assertArrayHasKey('body', $dto->getErrors());
    }

    public function testSingleFieldLeavesOthersNull(): void
    {
        $dto = UpdatePaymentClientRequest::fromArray(['fullName' => '  Ana ']);

        $this->assertTrue($dto->isValid());
        $this->assertSame('Ana', $dto->fullName);
        $this->assertNull($dto->phone);
        $this->assertNull($dto->email);
        $this->assertNull($dto->status);
    }

    public function testEmptyStringPhoneClearsValueInsteadOfBeingIgnored(): void
    {
        $dto = UpdatePaymentClientRequest::fromArray(['phone' => '']);

        $this->assertTrue($dto->isValid());
        $this->assertSame('', $dto->phone);
        $this->assertNull($dto->email);
    }

    public function testNullEmailClearsValue(): void
    {
        $dto = UpdatePaymentClientRequest::fromArray(['email' => null]);

        $this->assertTrue($dto->isValid());
        $this->assertSame('', $dto->email);
    }

    public function testSpacesOnlyEmailClearsValue(): void
    {
        $dto = UpdatePaymentClientRequest::fromArray(['email' => '   ']);

        $this->assertTrue($dto->isValid());
        $this->assertSame('', $dto->email);
    }

    public function testNullFullNameIsRejected(): void
    {
        $dto = UpdatePaymentClientRequest::fromArray(['fullName' => null]);

        $this->assertFalse($dto->isValid());
        $this->assertArrayHasKey('fullName', $dto->getErrors());
    }

    public function testBlankFullNameIsRejected(): void
    {
        $dto = UpdatePaymentClientRequest::fromArray(['fullName' => ' ']);

        $this->assertSame('Full name is required', $dto->getErrors()['fullName']);
    }

    public function testInvalidStatusIsRejected(): void
    {
        $this->assertArrayHasKey('status', UpdatePaymentClientRequest::fromArray(['status' => '1'])->getErrors());
        $this->assertArrayHasKey('status', UpdatePaymentClientRequest::fromArray(['status' => 3])->getErrors());
    }

    public function testValidStatusIsAccepted(): void
    {
        $dto = UpdatePaymentClientRequest::fromArray(['status' => 2]);

        $this->assertTrue($dto->isValid());
        $this->assertSame(2, $dto->status);
    }

    public function testInvalidEmailAndPhoneAreRejected(): void
    {
        $dto = UpdatePaymentClientRequest::fromArray([
            'email' => 'invalid',
            'phone' => str_repeat('9', 21),
        ]);

        $this->assertSame(['phone', 'email'], array_keys($dto->getErrors()));
    }
}
