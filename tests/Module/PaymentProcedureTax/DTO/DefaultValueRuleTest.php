<?php

declare(strict_types=1);

namespace Tests\Module\PaymentProcedureTax\DTO;

use App\Module\PaymentProcedureTax\DTO\DefaultValueRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefaultValueRuleTest extends TestCase
{
    #[DataProvider('validValues')]
    public function testValidateAcceptsNumbers(mixed $value): void
    {
        $this->assertNull(DefaultValueRule::validate($value));
    }

    /** @return array<string, array{mixed}> */
    public static function validValues(): array
    {
        return [
            'int' => [500],
            'float' => [12.5],
            'zero' => [0],
            'negative' => [-3],
            'huge' => [1.0e15],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testValidateRejectsNonNumbers(mixed $value): void
    {
        $this->assertSame('Default value must be a number', DefaultValueRule::validate($value));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidValues(): array
    {
        return [
            'string' => ['500'],
            'bool' => [true],
            'array' => [[1]],
            'null' => [null],
        ];
    }

    public function testNormalizeKeepsPositiveValues(): void
    {
        $this->assertSame(500.0, DefaultValueRule::normalize(500));
        $this->assertSame(12.5, DefaultValueRule::normalize(12.5));
        $this->assertSame(1.0e15, DefaultValueRule::normalize(1.0e15));
    }

    public function testNormalizeClampsNegativesNullAndNegativeZero(): void
    {
        $this->assertSame(0.0, DefaultValueRule::normalize(-10));
        $this->assertSame(0.0, DefaultValueRule::normalize(null));
        $this->assertSame(0.0, DefaultValueRule::normalize(0));
        $this->assertSame('0', (string) DefaultValueRule::normalize(-0.0));
    }
}
