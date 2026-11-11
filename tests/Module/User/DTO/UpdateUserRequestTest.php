<?php

declare(strict_types=1);

namespace Tests\Module\User\DTO;

use App\Module\User\DTO\UpdateUserRequest;
use PHPUnit\Framework\TestCase;

final class UpdateUserRequestTest extends TestCase
{
    public function testEmptyBodyIsRejected(): void
    {
        $dto = UpdateUserRequest::fromArray([]);

        $this->assertFalse($dto->isValid());
        $this->assertSame(
            ['body' => 'At least one field (username, firstName, paternalLastName, maternalLastName, access, status) is required'],
            $dto->getErrors()
        );
    }

    public function testPasswordIsRejectedExplicitly(): void
    {
        $dto = UpdateUserRequest::fromArray(['password' => 'x']);

        $this->assertFalse($dto->isValid());
        $this->assertSame('Password cannot be changed through this endpoint', $dto->getErrors()['password']);
        $this->assertArrayHasKey('body', $dto->getErrors());
    }

    public function testPasswordIsRejectedAlongsideValidFields(): void
    {
        $dto = UpdateUserRequest::fromArray(['firstName' => 'Ana', 'password' => 'whatever1']);

        $this->assertSame(['password'], array_keys($dto->getErrors()));
    }

    public function testLegacyUsernameIsValidInTheDto(): void
    {
        $dto = UpdateUserRequest::fromArray(['username' => 'FANNY RECEPCION']);

        $this->assertTrue($dto->isValid());
        $this->assertSame('FANNY RECEPCION', $dto->username);
    }

    public function testNonStringUsernameIsRejected(): void
    {
        $dto = UpdateUserRequest::fromArray(['username' => 5]);

        $this->assertSame(['username' => 'Username must be a string'], $dto->getErrors());
    }

    public function testMaternalLastNameNullOrEmptyClearsTheValue(): void
    {
        $this->assertSame('', UpdateUserRequest::fromArray(['maternalLastName' => null])->maternalLastName);
        $this->assertSame('', UpdateUserRequest::fromArray(['maternalLastName' => ''])->maternalLastName);
        $this->assertTrue(UpdateUserRequest::fromArray(['maternalLastName' => null])->isValid());
    }

    public function testAbsentFieldsStayNull(): void
    {
        $dto = UpdateUserRequest::fromArray(['firstName' => ' Ana ']);

        $this->assertTrue($dto->isValid());
        $this->assertSame('Ana', $dto->firstName);
        $this->assertNull($dto->username);
        $this->assertNull($dto->paternalLastName);
        $this->assertNull($dto->maternalLastName);
        $this->assertNull($dto->access);
        $this->assertNull($dto->status);
    }

    public function testAccessIsNormalizedToCanonicalOrder(): void
    {
        $this->assertSame(['ESC', 'PAG'], UpdateUserRequest::fromArray(['access' => ['PAG', 'ESC', 'ESC']])->access);
    }

    public function testInvalidFieldsReportTheirOwnKey(): void
    {
        $dto = UpdateUserRequest::fromArray([
            'firstName' => ' ',
            'paternalLastName' => 1,
            'maternalLastName' => 1,
            'access' => ['XYZ'],
            'status' => 3,
        ]);

        $this->assertSame(
            ['firstName', 'paternalLastName', 'maternalLastName', 'access', 'status'],
            array_keys($dto->getErrors())
        );
    }
}
