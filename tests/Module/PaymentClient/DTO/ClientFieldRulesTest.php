<?php

declare(strict_types=1);

namespace Tests\Module\PaymentClient\DTO;

use App\Module\PaymentClient\DTO\ClientFieldRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientFieldRulesTest extends TestCase
{
    // ---- fullName ----

    #[DataProvider('validFullNames')]
    public function testFullNameAcceptsValidValues(string $value): void
    {
        $this->assertNull(ClientFieldRules::fullName($value));
    }

    public static function validFullNames(): array
    {
        return [
            'simple' => ['Juan Pérez'],
            'padded' => ['  Juan  '],
            'exact limit' => [str_repeat('a', 250)],
            'limit with padding' => ['  ' . str_repeat('a', 250) . '  '],
            'multibyte at limit' => [str_repeat('ñ', 250)],
        ];
    }

    #[DataProvider('invalidFullNames')]
    public function testFullNameRejectsInvalidValues(mixed $value, string $message): void
    {
        $this->assertSame($message, ClientFieldRules::fullName($value));
    }

    public static function invalidFullNames(): array
    {
        return [
            'empty' => ['', 'Full name is required'],
            'spaces only' => ['   ', 'Full name is required'],
            'too long' => [str_repeat('a', 251), 'Full name must not exceed 250 characters'],
            'null' => [null, 'Full name must be a string'],
            'int' => [5, 'Full name must be a string'],
            'array' => [['a'], 'Full name must be a string'],
            'emoji' => ['Juan 😀', 'Full name contains unsupported characters'],
        ];
    }

    // ---- phone ----

    #[DataProvider('validPhones')]
    public function testPhoneAcceptsValidValues(mixed $value): void
    {
        $this->assertNull(ClientFieldRules::phone($value));
    }

    public static function validPhones(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'null' => [null],
            'exact limit' => [str_repeat('1', 20)],
            'limit with padding' => ['  ' . str_repeat('1', 20) . '  '],
            'free format' => ['+52 (55) 1234-5678'],
        ];
    }

    #[DataProvider('invalidPhones')]
    public function testPhoneRejectsInvalidValues(mixed $value, string $message): void
    {
        $this->assertSame($message, ClientFieldRules::phone($value));
    }

    public static function invalidPhones(): array
    {
        return [
            'too long' => [str_repeat('1', 21), 'Phone must not exceed 20 characters'],
            'not string' => [5551234, 'Phone must be a string'],
            'bool' => [true, 'Phone must be a string'],
            'emoji' => ['555 📞', 'Phone contains unsupported characters'],
        ];
    }

    // ---- email ----

    #[DataProvider('validEmails')]
    public function testEmailAcceptsValidValues(mixed $value): void
    {
        $this->assertNull(ClientFieldRules::email($value));
    }

    public static function validEmails(): array
    {
        return [
            'simple' => ['juan@example.com'],
            'padded' => ['  juan@example.com  '],
            'empty' => [''],
            'spaces only' => ['   '],
            'null' => [null],
        ];
    }

    #[DataProvider('invalidEmails')]
    public function testEmailRejectsInvalidValues(mixed $value, string $message): void
    {
        $this->assertSame($message, ClientFieldRules::email($value));
    }

    public static function invalidEmails(): array
    {
        return [
            'no at' => ['no-es-email', 'Email format is invalid'],
            'no domain' => ['juan@', 'Email format is invalid'],
            'accent' => ['josé@dominio.mx', 'Email format is invalid'],
            'emoji' => ['ju😀@example.com', 'Email format is invalid'],
            'too long' => [str_repeat('a', 245) . '@x.com', 'Email must not exceed 250 characters'],
            'not string' => [123, 'Email must be a string'],
            'array' => [['a@b.com'], 'Email must be a string'],
        ];
    }

    // ---- status ----

    public function testStatusAcceptsOneAndTwo(): void
    {
        $this->assertNull(ClientFieldRules::status(1));
        $this->assertNull(ClientFieldRules::status(2));
    }

    #[DataProvider('invalidStatuses')]
    public function testStatusRejectsOtherValues(mixed $value): void
    {
        $this->assertSame('Status must be 1 or 2', ClientFieldRules::status($value));
    }

    public static function invalidStatuses(): array
    {
        return [
            'zero' => [0],
            'three' => [3],
            'string one' => ['1'],
            'bool' => [true],
            'null' => [null],
            'float' => [1.0],
        ];
    }

    // ---- normalizeOptional ----

    public function testNormalizeOptionalTrimsStringsAndMapsOthersToEmpty(): void
    {
        $this->assertSame('abc', ClientFieldRules::normalizeOptional('  abc '));
        $this->assertSame('', ClientFieldRules::normalizeOptional(null));
        $this->assertSame('', ClientFieldRules::normalizeOptional('   '));
    }
}
