<?php

declare(strict_types=1);

namespace Tests\Module\Me\Service;

use App\Module\Me\Exception\MeError;
use App\Module\Me\Exception\MeException;
use App\Module\Me\Service\MeService;
use App\Module\Notary\Entity\Notary;
use App\Module\Notary\Repository\NotaryRepository;
use App\Module\User\Entity\User;
use App\Module\User\Repository\UserRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MeServiceTest extends TestCase
{
    private const USER_ID = 7;
    private const NOTARY = 1;

    private UserRepository&MockObject $users;
    private NotaryRepository&MockObject $notaries;
    private MeService $service;

    protected function setUp(): void
    {
        $this->users = $this->createMock(UserRepository::class);
        $this->notaries = $this->createMock(NotaryRepository::class);
        $this->service = new MeService($this->users, $this->notaries);
    }

    private static function user(int $notaryId = self::NOTARY, int $statusId = 1): User
    {
        return new User(
            id: self::USER_ID,
            notaryId: $notaryId,
            username: 'maria.lopez',
            firstName: 'María',
            paternalLastName: 'López',
            maternalLastName: 'García',
            access: ['ESC'],
            statusId: $statusId,
            lastAccess: null,
        );
    }

    private static function notary(int $status = 1): Notary
    {
        return new Notary(
            id: self::NOTARY,
            name: 'Notaría 1',
            notaryName: 'Lic. Pérez',
            city: 'CDMX',
            address: 'Calle 1',
            phone: '5555555555',
            rfc: 'XAXX010101000',
            logo: '',
            status: $status,
        );
    }

    private function assertFails(MeError $expected): void
    {
        try {
            $this->service->getProfile(self::USER_ID, self::NOTARY);
            $this->fail('MeException expected');
        } catch (MeException $e) {
            $this->assertSame($expected, $e->error);
        }
    }

    public function testReturnsUserAndNotary(): void
    {
        $user = self::user();
        $notary = self::notary();
        $this->users->expects($this->once())->method('findById')->with(self::USER_ID)->willReturn($user);
        $this->notaries->expects($this->once())->method('findById')->with(self::NOTARY)->willReturn($notary);

        $profile = $this->service->getProfile(self::USER_ID, self::NOTARY);

        $this->assertSame($user, $profile->user);
        $this->assertSame($notary, $profile->notary);
    }

    public function testUserNotFound(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->notaries->expects($this->never())->method('findById');

        $this->assertFails(MeError::UserNotFound);
    }

    public function testTenantMismatchDoesNotReadNotary(): void
    {
        $this->users->method('findById')->willReturn(self::user(notaryId: 2));
        $this->notaries->expects($this->never())->method('findById');

        $this->assertFails(MeError::TenantMismatch);
    }

    public function testInactiveUserDoesNotReadNotary(): void
    {
        $this->users->method('findById')->willReturn(self::user(statusId: 2));
        $this->notaries->expects($this->never())->method('findById');

        $this->assertFails(MeError::UserInactive);
    }

    public function testLegacyZeroStatusUserIsInactive(): void
    {
        $this->users->method('findById')->willReturn(self::user(statusId: 0));

        $this->assertFails(MeError::UserInactive);
    }

    public function testInactiveNotary(): void
    {
        $this->users->method('findById')->willReturn(self::user());
        $this->notaries->method('findById')->willReturn(self::notary(status: 2));

        $this->assertFails(MeError::NotaryInactive);
    }

    public function testMissingNotaryIsRejectedAsInactive(): void
    {
        $this->users->method('findById')->willReturn(self::user());
        $this->notaries->method('findById')->willReturn(null);

        $this->assertFails(MeError::NotaryInactive);
    }
}
