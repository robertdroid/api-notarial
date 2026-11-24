<?php

declare(strict_types=1);

namespace Tests\Module\PaymentTransaction\DTO;

use App\Module\PaymentTransaction\DTO\TransactionFieldRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionFieldRulesTest extends TestCase
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
        $this->assertSame($expected, TransactionFieldRules::positiveInt($value));
    }

    /** @return array<string, array{mixed, bool}> */
    public static function uuids(): array
    {
        return [
            'one char' => ['a', true],
            'mixed case kept' => ['AbC-12_x', true],
            '20 chars' => [str_repeat('a', 20), true],
            '21 chars' => [str_repeat('a', 21), false],
            'empty' => ['', false],
            'space' => ['a b', false],
            'trailing newline' => ["abc\n", false],
            'symbol' => ['abc!', false],
            'int' => [123, false],
            'array' => [['x'], false],
        ];
    }

    #[DataProvider('uuids')]
    public function testUuid(mixed $value, bool $valid): void
    {
        $this->assertSame($valid, TransactionFieldRules::uuid($value) === null);
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
        $this->assertSame($expected, TransactionFieldRules::date($value));
    }

    /** @return array<string, array{mixed, ?string}> */
    public static function moneyValues(): array
    {
        return [
            'zero' => [0, null], 'int' => [10, null], 'float' => [10.55, null], 'max' => [999999999.99, null],
            'too large' => [1000000000, 'Fee is too large'], 'negative' => [-0.01, 'Fee cannot be negative'],
            'string' => ['10', 'Fee must be a number'], 'bool' => [true, 'Fee must be a number'],
            'null' => [null, 'Fee must be a number'], 'infinite' => [INF, 'Fee must be a number'],
            'nan' => [NAN, 'Fee must be a number'],
        ];
    }

    #[DataProvider('moneyValues')]
    public function testMoney(mixed $value, ?string $error): void
    {
        $this->assertSame($error, TransactionFieldRules::money($value, 'Fee'));
    }

    /** @return array<string, array{mixed, ?string}> */
    public static function quantities(): array
    {
        return [
            'one' => [1, null], 'max' => [9999, null],
            'zero' => [0, 'Detail quantity must be at least 1'], 'negative' => [-1, 'Detail quantity must be at least 1'],
            'too large' => [10000, 'Detail quantity is too large'],
            'float' => [1.5, 'Detail quantity must be an integer'], 'string' => ['2', 'Detail quantity must be an integer'],
        ];
    }

    #[DataProvider('quantities')]
    public function testQuantity(mixed $value, ?string $error): void
    {
        $this->assertSame($error, TransactionFieldRules::quantity($value));
    }

    public function testTextLimitsAreMeasuredInBytes(): void
    {
        $this->assertNull(TransactionFieldRules::text(str_repeat('a', 65535), 'Comments'));
        $this->assertSame('Comments is too long', TransactionFieldRules::text(str_repeat('a', 65536), 'Comments'));
        // 32768 two-byte characters = 65536 bytes
        $this->assertSame('Comments is too long', TransactionFieldRules::text(str_repeat('ñ', 32768), 'Comments'));
        $this->assertSame('Comments must be a string', TransactionFieldRules::text(5, 'Comments'));
    }
}
