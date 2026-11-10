<?php

declare(strict_types=1);

namespace Tests\Module\PaymentProcedureTax\DTO;

use App\Module\PaymentProcedureTax\DTO\UpdateProcedureTaxRequest;
use PHPUnit\Framework\TestCase;

final class UpdateProcedureTaxRequestTest extends TestCase
{
    public function testValidValue(): void
    {
        $dto = UpdateProcedureTaxRequest::fromArray(['defaultValue' => 750.25]);

        $this->assertTrue($dto->isValid());
        $this->assertSame(750.25, $dto->defaultValue);
    }

    public function testDefaultValueIsRequired(): void
    {
        $this->assertSame(
            ['defaultValue' => 'Default value is required'],
            UpdateProcedureTaxRequest::fromArray([])->getErrors()
        );
        $this->assertFalse(UpdateProcedureTaxRequest::fromArray(null)->isValid());
    }

    public function testNullIsRejected(): void
    {
        $dto = UpdateProcedureTaxRequest::fromArray(['defaultValue' => null]);

        $this->assertSame(['defaultValue' => 'Default value must be a number'], $dto->getErrors());
    }

    public function testStringIsRejected(): void
    {
        $dto = UpdateProcedureTaxRequest::fromArray(['defaultValue' => '10']);

        $this->assertSame(['defaultValue' => 'Default value must be a number'], $dto->getErrors());
    }

    public function testNegativeBecomesZero(): void
    {
        $dto = UpdateProcedureTaxRequest::fromArray(['defaultValue' => -20]);

        $this->assertTrue($dto->isValid());
        $this->assertSame(0.0, $dto->defaultValue);
    }
}
