<?php

declare(strict_types=1);

namespace Tests\Module\Me\Exception;

use App\Module\Me\Exception\MeError;
use App\Module\Me\Exception\MeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MeErrorTest extends TestCase
{
    /** @return array<string, array{MeError, string, int}> */
    public static function errors(): array
    {
        return [
            'user not found' => [MeError::UserNotFound, 'User not found', 404],
            'tenant mismatch' => [MeError::TenantMismatch, 'Forbidden', 403],
            'user inactive' => [MeError::UserInactive, 'User is inactive', 403],
            'notary inactive' => [MeError::NotaryInactive, 'Notary is inactive', 403],
        ];
    }

    #[DataProvider('errors')]
    public function testMessageAndHttpStatus(MeError $error, string $message, int $status): void
    {
        $this->assertSame($message, $error->message());
        $this->assertSame($status, $error->httpStatus());
        $this->assertSame($message, (new MeException($error))->getMessage());
    }
}
