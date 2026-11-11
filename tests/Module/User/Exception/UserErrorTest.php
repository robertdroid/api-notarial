<?php

declare(strict_types=1);

namespace Tests\Module\User\Exception;

use App\Module\User\Exception\UserError;
use App\Module\User\Exception\UserException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserErrorTest extends TestCase
{
    /** @return array<string, array{UserError, string, int}> */
    public static function errors(): array
    {
        return [
            'not found' => [UserError::UserNotFound, 'User not found', 404],
            'username taken' => [UserError::UsernameTaken, 'Username already exists', 409],
            'deactivate self' => [UserError::CannotDeactivateSelf, 'Cannot deactivate your own account', 403],
            'invalid username' => [
                UserError::InvalidUsername,
                'Username must be 3 to 20 characters (letters, digits, . or _)',
                400,
            ],
        ];
    }

    #[DataProvider('errors')]
    public function testMessageAndHttpStatus(UserError $error, string $message, int $status): void
    {
        $this->assertSame($message, $error->message());
        $this->assertSame($status, $error->httpStatus());
    }

    public function testExceptionCarriesTheErrorAndItsMessage(): void
    {
        $exception = new UserException(UserError::UsernameTaken);

        $this->assertSame(UserError::UsernameTaken, $exception->error);
        $this->assertSame('Username already exists', $exception->getMessage());
    }
}
