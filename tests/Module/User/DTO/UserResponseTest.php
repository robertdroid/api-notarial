<?php

declare(strict_types=1);

namespace Tests\Module\User\DTO;

use App\Module\User\DTO\UserResponse;
use App\Module\User\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserResponseTest extends TestCase
{
    private static function user(int $statusId = 1, ?string $lastAccess = '2026-11-10 08:30:00'): User
    {
        return new User(
            id: 7,
            notaryId: 1,
            username: 'maria.lopez',
            firstName: 'María',
            paternalLastName: 'López',
            maternalLastName: '',
            access: ['ESC', 'PAG'],
            statusId: $statusId,
            lastAccess: $lastAccess !== null ? new \DateTimeImmutable($lastAccess) : null,
        );
    }

    public function testExactKeySetWithoutSensitiveOrTenantFields(): void
    {
        $data = UserResponse::fromEntity(self::user());

        $this->assertSame(
            ['id', 'username', 'firstName', 'paternalLastName', 'maternalLastName', 'access', 'status', 'lastAccess'],
            array_keys($data)
        );
    }

    public function testValues(): void
    {
        $this->assertSame([
            'id' => 7,
            'username' => 'maria.lopez',
            'firstName' => 'María',
            'paternalLastName' => 'López',
            'maternalLastName' => '',
            'access' => ['ESC', 'PAG'],
            'status' => 1,
            'lastAccess' => '2026-11-10T08:30:00',
        ], UserResponse::fromEntity(self::user()));
    }

    public function testLegacyZeroStatusIsExposedAsInactive(): void
    {
        $this->assertSame(2, UserResponse::fromEntity(self::user(statusId: 0))['status']);
    }

    public function testNullLastAccess(): void
    {
        $this->assertNull(UserResponse::fromEntity(self::user(lastAccess: null))['lastAccess']);
    }

    public function testFromCollection(): void
    {
        $data = UserResponse::fromCollection([self::user(), self::user(2)]);

        $this->assertCount(2, $data);
        $this->assertSame(2, $data[1]['status']);
    }
}
