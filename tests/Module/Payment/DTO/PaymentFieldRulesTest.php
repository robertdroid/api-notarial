<?php

declare(strict_types=1);

namespace Tests\Module\Payment\DTO;

use App\Module\Payment\DTO\PaymentFieldRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentFieldRulesTest extends TestCase
{
    /** @return array<string, array{mixed, bool}> */
    public static function positiveInts(): array
    {
        return [
            'one' => [1, true], 'big' => [99999, true], 'zero' => [0, false], 'negative' => [-1, false],
            'numeric string' => ['5', false], 'float' => [5.0, false], 'bool' => [true, false], 'null' => [null, false],
        ];
    }

    #[DataProvider('positiveInts')]
    public function testPositiveInt(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, PaymentFieldRules::positiveInt($value));
    }

    public function testUuidDelegatesToClientUuid(): void
    {
        $this->assertNull(PaymentFieldRules::uuid('Us2Gvq'));
        $this->assertNotNull(PaymentFieldRules::uuid(''));
        $this->assertNotNull(PaymentFieldRules::uuid(['x']));
    }

    /** @return array<string, array{mixed, bool}> */
    public static function dates(): array
    {
        return [
            'valid' => ['2024-01-15', true], 'leap day' => ['2024-02-29', true],
            'nonexistent day' => ['2024-02-30', false], 'not padded' => ['2024-1-5', false],
            'slashes' => ['15/01/2024', false], 'empty' => ['', false], 'with time' => ['2024-01-15 10:00:00', false],
            'int' => [20240115, false], 'null' => [null, false],
        ];
    }

    #[DataProvider('dates')]
    public function testDate(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, PaymentFieldRules::date($value));
    }

    /** @return array<string, array{mixed, ?string}> */
    public static function amounts(): array
    {
        return [
            'int' => [5000, null], 'float' => [5000.55, null], 'three decimals' => [10.555, null],
            'tiny positive' => [0.01, null], 'max' => [999999999.99, null],
            'zero' => [0, 'Amount must be greater than zero'],
            'zero float' => [0.0, 'Amount must be greater than zero'],
            'negative' => [-1, 'Amount must be greater than zero'],
            'too large' => [1000000000, 'Amount is too large'],
            'numeric string' => ['5000', 'Amount must be a number'],
            'bool' => [true, 'Amount must be a number'],
            'null' => [null, 'Amount must be a number'],
            'array' => [[1], 'Amount must be a number'],
            'infinite' => [INF, 'Amount must be a number'],
            'nan' => [NAN, 'Amount must be a number'],
        ];
    }

    #[DataProvider('amounts')]
    public function testAmount(mixed $value, ?string $error): void
    {
        $this->assertSame($error, PaymentFieldRules::amount($value));
    }

    public function testTextLimitsAreMeasuredInBytes(): void
    {
        $this->assertNull(PaymentFieldRules::text(str_repeat('a', 65535), 'Comments'));
        $this->assertSame('Comments is too long', PaymentFieldRules::text(str_repeat('a', 65536), 'Comments'));
        // 32768 two-byte characters = 65536 bytes
        $this->assertSame('Comments is too long', PaymentFieldRules::text(str_repeat('ñ', 32768), 'Comments'));
        $this->assertSame('Comments must be a string', PaymentFieldRules::text(5, 'Comments'));
    }
}
