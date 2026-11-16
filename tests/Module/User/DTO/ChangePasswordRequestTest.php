<?php

declare(strict_types=1);

namespace Tests\Module\User\DTO;

use App\Module\User\DTO\ChangePasswordRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChangePasswordRequestTest extends TestCase
{
    public function testAcceptsExactlyEightCharacters(): void
    {
        $dto = ChangePasswordRequest::fromArray(['password' => '12345678']);

        $this->assertTrue($dto->isValid());
        $this->assertSame('12345678', $dto->password);
    }

    public function testAcceptsExactly72Bytes(): void
    {
        $this->assertTrue(ChangePasswordRequest::fromArray(['password' => str_repeat('a', 72)])->isValid());
    }

    public function testRejectsSevenCharacters(): void
    {
        $dto = ChangePasswordRequest::fromArray(['password' => '1234567']);

        $this->assertFalse($dto->isValid());
        $this->assertArrayHasKey('password', $dto->getErrors());
    }

    public function testRejects73Bytes(): void
    {
        $this->assertFalse(ChangePasswordRequest::fromArray(['password' => str_repeat('a', 73)])->isValid());
    }

    public function testRejectsEightMultibyteCharactersOver72Bytes(): void
    {
        // 19 four-byte characters: passes the 8-character minimum but is 76 bytes (> 72)
        $this->assertFalse(ChangePasswordRequest::fromArray(['password' => str_repeat('😀', 19)])->isValid());
    }

    public function testRejectsMissingPassword(): void
    {
        $dto = ChangePasswordRequest::fromArray([]);

        $this->assertFalse($dto->isValid());
        $this->assertSame(['password' => 'Password is required'], $dto->getErrors());
    }

    /** @return array<string, array{mixed}> */
    public static function nonStringProvider(): array
    {
        return ['null' => [null], 'int' => [12345678], 'array' => [['abcdefgh']]];
    }

    #[DataProvider('nonStringProvider')]
    public function testRejectsNonString(mixed $value): void
    {
        $dto = ChangePasswordRequest::fromArray(['password' => $value]);

        $this->assertFalse($dto->isValid());
        $this->assertArrayHasKey('password', $dto->getErrors());
    }

    public function testRejectsNulByte(): void
    {
        $this->assertFalse(ChangePasswordRequest::fromArray(['password' => "abcd\0efgh"])->isValid());
    }

    public function testKeepsSurroundingWhitespace(): void
    {
        $dto = ChangePasswordRequest::fromArray(['password' => '  abcdef  ']);

        $this->assertTrue($dto->isValid());
        $this->assertSame('  abcdef  ', $dto->password);
    }

    public function testIgnoresExtraFields(): void
    {
        $this->assertTrue(ChangePasswordRequest::fromArray(['password' => '12345678', 'id' => 9, 'foo' => 'x'])->isValid());
    }

    public function testErrorMessageDoesNotContainPassword(): void
    {
        $secret = 'short!!';
        $dto = ChangePasswordRequest::fromArray(['password' => $secret]);

        $this->assertStringNotContainsString($secret, json_encode($dto->getErrors(), JSON_THROW_ON_ERROR));
    }
}
