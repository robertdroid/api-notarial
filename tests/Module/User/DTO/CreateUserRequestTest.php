<?php

declare(strict_types=1);

namespace Tests\Module\User\DTO;

use App\Module\User\DTO\CreateUserRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreateUserRequestTest extends TestCase
{
    private const SECRET = 'S3cr3t-Pass';

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function valid(array $overrides = []): array
    {
        return array_merge([
            'username' => 'maria.lopez',
            'password' => self::SECRET,
            'firstName' => '  María ',
            'paternalLastName' => 'López',
            'maternalLastName' => ' García ',
            'access' => ['PAG', 'ESC', 'PAG'],
            'status' => 2,
        ], $overrides);
    }

    public function testFullValidBody(): void
    {
        $dto = CreateUserRequest::fromArray(self::valid());

        $this->assertTrue($dto->isValid());
        $this->assertSame('maria.lopez', $dto->username);
        $this->assertSame(self::SECRET, $dto->password);
        $this->assertSame('María', $dto->firstName);
        $this->assertSame('López', $dto->paternalLastName);
        $this->assertSame('García', $dto->maternalLastName);
        $this->assertSame(['ESC', 'PAG'], $dto->access);
        $this->assertSame(2, $dto->status);
    }

    public function testMinimalBodyAppliesDefaults(): void
    {
        $dto = CreateUserRequest::fromArray([
            'username' => 'abc',
            'password' => '12345678',
            'firstName' => 'Ana',
            'paternalLastName' => 'Pérez',
            'access' => ['ESC'],
        ]);

        $this->assertTrue($dto->isValid());
        $this->assertSame(1, $dto->status);
        $this->assertSame('', $dto->maternalLastName);
    }

    public function testNullMaternalLastNameBecomesEmptyString(): void
    {
        $dto = CreateUserRequest::fromArray(self::valid(['maternalLastName' => null]));

        $this->assertTrue($dto->isValid());
        $this->assertSame('', $dto->maternalLastName);
    }

    /** @return array<string, array{string, string}> */
    public static function requiredFields(): array
    {
        return [
            'username' => ['username', 'Username is required'],
            'password' => ['password', 'Password is required'],
            'firstName' => ['firstName', 'First name is required'],
            'paternalLastName' => ['paternalLastName', 'Paternal last name is required'],
            'access' => ['access', 'Access is required'],
        ];
    }

    #[DataProvider('requiredFields')]
    public function testMissingRequiredField(string $field, string $message): void
    {
        $body = self::valid();
        unset($body[$field]);

        $dto = CreateUserRequest::fromArray($body);

        $this->assertFalse($dto->isValid());
        $this->assertSame([$field => $message], $dto->getErrors());
    }

    public function testInvalidFieldsReportTheirOwnKey(): void
    {
        $dto = CreateUserRequest::fromArray(self::valid([
            'username' => 'a b',
            'password' => 'short',
            'firstName' => '',
            'paternalLastName' => 5,
            'maternalLastName' => str_repeat('a', 151),
            'access' => [],
            'status' => '1',
        ]));

        $this->assertSame(
            ['username', 'password', 'firstName', 'paternalLastName', 'maternalLastName', 'access', 'status'],
            array_keys($dto->getErrors())
        );
    }

    public function testNoErrorMessageContainsThePassword(): void
    {
        $dto = CreateUserRequest::fromArray(self::valid(['password' => self::SECRET . "\0", 'username' => '!']));

        $this->assertFalse($dto->isValid());
        $this->assertStringNotContainsString(self::SECRET, json_encode($dto->getErrors(), JSON_THROW_ON_ERROR));
    }
}
