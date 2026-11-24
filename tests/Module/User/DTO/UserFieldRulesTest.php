<?php

declare(strict_types=1);

namespace Tests\Module\User\DTO;

use App\Module\User\DTO\UserFieldRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserFieldRulesTest extends TestCase
{
    /** @return array<string, array{mixed, bool}> */
    public static function usernames(): array
    {
        return [
            'dotted' => ['maria.lopez', true],
            'underscore' => ['a_b', true],
            'mixed case' => ['Maria.Lopez', true],
            '20 chars' => [str_repeat('a', 20), true],
            'too short' => ['ab', false],
            '21 chars' => [str_repeat('a', 21), false],
            'space (legacy)' => ['FANNY RECEPCION', false],
            'accent' => ['maría', false],
            'trailing newline' => ["maria\n", false],
            'empty' => ['', false],
            'integer' => [123, false],
            'null' => [null, false],
            'array' => [['a'], false],
        ];
    }

    #[DataProvider('usernames')]
    public function testUsername(mixed $value, bool $valid): void
    {
        $this->assertSame($valid, UserFieldRules::username($value) === null);
    }

    public function testUsernameMessages(): void
    {
        $this->assertSame('Username must be a string', UserFieldRules::username(5));
        $this->assertSame(UserFieldRules::USERNAME_FORMAT_MESSAGE, UserFieldRules::username('ab'));
    }

    /** @return array<string, array{mixed, bool}> */
    public static function passwords(): array
    {
        return [
            '8 chars' => ['12345678', true],
            '7 chars' => ['1234567', false],
            '8 spaces' => ['        ', true],
            '72 bytes' => [str_repeat('a', 72), true],
            '73 bytes' => [str_repeat('a', 73), false],
            'NUL byte' => ["abcdefg\0h", false],
            'accents count as chars' => ['ñññññññ1', true],
            'integer' => [12345678, false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('passwords')]
    public function testPassword(mixed $value, bool $valid): void
    {
        $this->assertSame($valid, UserFieldRules::password($value) === null);
    }

    public function testPasswordMessagesNeverContainTheValue(): void
    {
        foreach (['short', str_repeat('x', 73), "ab\0cdefgh"] as $value) {
            $message = (string) UserFieldRules::password($value);
            $this->assertNotSame('', $message);
            $this->assertStringNotContainsString($value, $message);
        }
    }

    /** @return array<string, array{mixed, bool}> */
    public static function requiredNames(): array
    {
        return [
            'plain' => ['María', true],
            '150 chars with accents' => [str_repeat('é', 150), true],
            '151 chars' => [str_repeat('a', 151), false],
            'empty' => ['', false],
            'only spaces' => ['   ', false],
            'emoji' => ['Ana 😀', false],
            'integer' => [1, false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('requiredNames')]
    public function testRequiredName(mixed $value, bool $valid): void
    {
        $this->assertSame($valid, UserFieldRules::requiredName($value, 'First name') === null);
    }

    public function testRequiredNameMessagesUseTheLabel(): void
    {
        $this->assertSame('First name is required', UserFieldRules::requiredName('  ', 'First name'));
        $this->assertSame('First name must be a string', UserFieldRules::requiredName(1, 'First name'));
        $this->assertSame(
            'First name must not exceed 150 characters',
            UserFieldRules::requiredName(str_repeat('a', 151), 'First name')
        );
        $this->assertSame(
            'First name contains unsupported characters',
            UserFieldRules::requiredName('😀', 'First name')
        );
    }

    /** @return array<string, array{mixed, bool}> */
    public static function optionalNames(): array
    {
        return [
            'null' => [null, true],
            'empty' => ['', true],
            'spaces' => ['   ', true],
            'plain' => ['García', true],
            '151 chars' => [str_repeat('a', 151), false],
            'emoji' => ['😀', false],
            'integer' => [1, false],
        ];
    }

    #[DataProvider('optionalNames')]
    public function testOptionalName(mixed $value, bool $valid): void
    {
        $this->assertSame($valid, UserFieldRules::optionalName($value, 'Maternal last name') === null);
    }

    /** @return array<string, array{mixed, bool}> */
    public static function accessLists(): array
    {
        return [
            'one' => [['ESC'], true],
            'both reversed' => [['PAG', 'ESC'], true],
            'duplicates' => [['ESC', 'ESC'], true],
            'empty' => [[], false],
            'unknown' => [['XYZ'], false],
            'lowercase' => [['esc'], false],
            'string' => ['ESC', false],
            'integer element' => [[1], false],
            'assoc' => [['a' => 'ESC'], false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('accessLists')]
    public function testAccess(mixed $value, bool $valid): void
    {
        $this->assertSame($valid, UserFieldRules::access($value) === null);
    }

    public function testAccessMessages(): void
    {
        $this->assertSame('Access must be an array', UserFieldRules::access('ESC'));
        $this->assertSame('At least one access code is required', UserFieldRules::access([]));
        $this->assertSame('Access codes must be one of: ESC, PAG', UserFieldRules::access(['XYZ']));
    }

    /** @return array<string, array{mixed, bool}> */
    public static function statuses(): array
    {
        return [
            'active' => [1, true],
            'inactive' => [2, true],
            'zero' => [0, false],
            'three' => [3, false],
            'numeric string' => ['1', false],
            'bool' => [true, false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('statuses')]
    public function testStatus(mixed $value, bool $valid): void
    {
        $this->assertSame($valid, UserFieldRules::status($value) === null);
    }

    public function testNormalizeOptional(): void
    {
        $this->assertSame('x', UserFieldRules::normalizeOptional('  x '));
        $this->assertSame('', UserFieldRules::normalizeOptional(null));
    }
}
