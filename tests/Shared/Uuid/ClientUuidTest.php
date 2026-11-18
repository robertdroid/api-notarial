<?php

declare(strict_types=1);

namespace Tests\Shared\Uuid;

use App\Shared\Uuid\ClientUuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientUuidTest extends TestCase
{
    /** @return array<string, array{mixed, bool}> */
    public static function uuids(): array
    {
        return [
            'default length (6)' => [str_repeat('a', ClientUuid::DEFAULT_LENGTH), true],
            'one char' => ['a', true],
            'mixed case kept' => ['AbC-12_x', true],
            'max length (20)' => [str_repeat('a', ClientUuid::MAX_LENGTH), true],
            '21 chars' => [str_repeat('a', ClientUuid::MAX_LENGTH + 1), false],
            'empty' => ['', false],
            'only spaces' => ['   ', false],
            'leading space' => [' abc', false],
            'trailing space' => ['abc ', false],
            'trailing newline' => ["abc\n", false],
            'inner space' => ['a b', false],
            'symbol' => ['abc!', false],
            'accent' => ['abcñ', false],
            'int' => [123, false],
            'null' => [null, false],
            'array' => [['x'], false],
        ];
    }

    #[DataProvider('uuids')]
    public function testValidate(mixed $value, bool $valid): void
    {
        $error = ClientUuid::validate($value);

        $this->assertSame($valid ? null : ClientUuid::ERROR_MESSAGE, $error);
    }

    public function testConstants(): void
    {
        $this->assertSame(6, ClientUuid::DEFAULT_LENGTH);
        $this->assertSame(20, ClientUuid::MAX_LENGTH);
    }

    public function testCaseIsNotNormalized(): void
    {
        // Both are valid and distinct values: the validator never changes the case
        $this->assertNull(ClientUuid::validate('abc'));
        $this->assertNull(ClientUuid::validate('ABC'));
        $this->assertNotSame('abc', 'ABC');
    }
}
