<?php

declare(strict_types=1);

namespace Tests\Module\User\Service;

use App\Module\User\DTO\CreateUserRequest;
use App\Module\User\DTO\UpdateUserRequest;
use App\Module\User\Entity\User;
use App\Module\User\Exception\DuplicateUsernameException;
use App\Module\User\Exception\UserError;
use App\Module\User\Exception\UserException;
use App\Module\User\Repository\UserRepository;
use App\Module\User\Service\UserService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
    private const NOTARY = 1;
    private const USER_ID = 10;
    private const ACTOR_ID = 5;
    private const PASSWORD = 'S3cr3t-Pass';

    private UserService $service;
    private UserRepository&MockObject $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(UserRepository::class);
        $this->service = new UserService($this->repository);
    }

    private static function user(
        int $id = self::USER_ID,
        string $username = 'maria.lopez',
        int $statusId = 1,
    ): User {
        return new User(
            id: $id,
            notaryId: self::NOTARY,
            username: $username,
            firstName: 'María',
            paternalLastName: 'López',
            maternalLastName: 'García',
            access: ['ESC', 'PAG'],
            statusId: $statusId,
            lastAccess: null,
        );
    }

    /** @param array<string, mixed> $overrides */
    private static function createDto(array $overrides = []): CreateUserRequest
    {
        return CreateUserRequest::fromArray(array_merge([
            'username' => 'maria.lopez',
            'password' => self::PASSWORD,
            'firstName' => 'María',
            'paternalLastName' => 'López',
            'access' => ['ESC'],
        ], $overrides));
    }

    /** @param array<string, mixed> $body */
    private static function updateDto(array $body): UpdateUserRequest
    {
        return UpdateUserRequest::fromArray($body);
    }

    private function assertUserError(UserError $expected, callable $operation): void
    {
        try {
            $operation();
        } catch (UserException $e) {
            $this->assertSame($expected, $e->error);

            return;
        }

        $this->fail('Expected UserException with ' . $expected->name);
    }

    // ---- reads -------------------------------------------------------------------

    public function testGetAllDelegatesToTheRepository(): void
    {
        $users = [self::user()];
        $this->repository->expects($this->once())
            ->method('findAllByNotary')->with(self::NOTARY, 2)->willReturn($users);

        $this->assertSame($users, $this->service->getAll(self::NOTARY, 2));
    }

    public function testGetByIdReturnsTheUser(): void
    {
        $user = self::user();
        $this->repository->method('findByIdAndNotary')->with(self::USER_ID, self::NOTARY)->willReturn($user);

        $this->assertSame($user, $this->service->getById(self::USER_ID, self::NOTARY));
    }

    public function testGetByIdThrowsWhenMissing(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(null);

        $this->assertUserError(
            UserError::UserNotFound,
            fn () => $this->service->getById(self::USER_ID, self::NOTARY)
        );
    }

    // ---- create ------------------------------------------------------------------

    public function testCreateHashesThePasswordWithBcryptCost10AndReturnsTheReloadedUser(): void
    {
        $created = self::user(13);
        $this->repository->method('usernameExists')->with('maria.lopez')->willReturn(false);

        $capturedHash = null;
        $this->repository->expects($this->once())
            ->method('create')
            ->willReturnCallback(function (int $notaryId, CreateUserRequest $dto, string $hash) use (&$capturedHash): int {
                $this->assertSame(self::NOTARY, $notaryId);
                $capturedHash = $hash;

                return 13;
            });
        $this->repository->method('findByIdAndNotary')->with(13, self::NOTARY)->willReturn($created);

        $result = $this->service->create(self::createDto(), self::NOTARY);

        $this->assertSame($created, $result);
        $this->assertNotNull($capturedHash);
        $this->assertTrue(password_verify(self::PASSWORD, $capturedHash));
        $this->assertStringStartsWith('$2y$10$', $capturedHash);
    }

    public function testCreateWithTakenUsernameNeverWrites(): void
    {
        $this->repository->method('usernameExists')->willReturn(true);
        $this->repository->expects($this->never())->method('create');

        $this->assertUserError(
            UserError::UsernameTaken,
            fn () => $this->service->create(self::createDto(), self::NOTARY)
        );
    }

    public function testCreateTranslatesDuplicateUsernameRace(): void
    {
        $this->repository->method('usernameExists')->willReturn(false);
        $this->repository->method('create')->willThrowException(new DuplicateUsernameException());

        $this->assertUserError(
            UserError::UsernameTaken,
            fn () => $this->service->create(self::createDto(), self::NOTARY)
        );
    }

    // ---- update ------------------------------------------------------------------

    public function testUpdateOfMissingUserThrowsNotFound(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(null);
        $this->repository->expects($this->never())->method('update');

        $this->assertUserError(
            UserError::UserNotFound,
            fn () => $this->service->update(self::USER_ID, self::updateDto(['firstName' => 'Ana']), self::NOTARY, self::ACTOR_ID)
        );
    }

    public function testUserCannotDeactivateThemselves(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(self::user(self::ACTOR_ID));
        $this->repository->expects($this->never())->method('update');

        $this->assertUserError(
            UserError::CannotDeactivateSelf,
            fn () => $this->service->update(self::ACTOR_ID, self::updateDto(['status' => 2]), self::NOTARY, self::ACTOR_ID)
        );
    }

    public function testUserCanSetThemselvesActive(): void
    {
        $existing = self::user(self::ACTOR_ID);
        $this->repository->method('findByIdAndNotary')->willReturn($existing);
        $this->repository->expects($this->once())->method('update');

        $result = $this->service->update(self::ACTOR_ID, self::updateDto(['status' => 1]), self::NOTARY, self::ACTOR_ID);

        $this->assertSame($existing, $result);
    }

    public function testUserCanDeactivateAnotherUser(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(self::user());
        $this->repository->expects($this->once())->method('update')
            ->with($this->callback(static fn (User $u): bool => $u->statusId === 2));

        $this->service->update(self::USER_ID, self::updateDto(['status' => 2]), self::NOTARY, self::ACTOR_ID);
    }

    public function testSelfUpdateWithoutStatusIsAllowed(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(self::user(self::ACTOR_ID));
        $this->repository->expects($this->once())->method('update');

        $this->service->update(self::ACTOR_ID, self::updateDto(['firstName' => 'Ana']), self::NOTARY, self::ACTOR_ID);
    }

    public function testUnchangedLegacyUsernameSkipsFormatAndUniquenessChecks(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(self::user(6, 'FANNY RECEPCION'));
        $this->repository->expects($this->never())->method('usernameExists');
        $this->repository->expects($this->once())->method('update');

        $this->service->update(
            6,
            self::updateDto(['username' => 'FANNY RECEPCION', 'firstName' => 'Fanny']),
            self::NOTARY,
            self::ACTOR_ID
        );
    }

    public function testChangedUsernameWithInvalidFormatIsRejected(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(self::user(6, 'FANNY RECEPCION'));
        $this->repository->expects($this->never())->method('usernameExists');
        $this->repository->expects($this->never())->method('update');

        $this->assertUserError(
            UserError::InvalidUsername,
            fn () => $this->service->update(6, self::updateDto(['username' => 'FANNY R']), self::NOTARY, self::ACTOR_ID)
        );
    }

    public function testChangedUsernameThatIsTakenIsRejectedExcludingTheOwnId(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(self::user());
        $this->repository->expects($this->once())
            ->method('usernameExists')->with('maria.nueva', self::USER_ID)->willReturn(true);
        $this->repository->expects($this->never())->method('update');

        $this->assertUserError(
            UserError::UsernameTaken,
            fn () => $this->service->update(self::USER_ID, self::updateDto(['username' => 'maria.nueva']), self::NOTARY, self::ACTOR_ID)
        );
    }

    public function testUsernameCaseOnlyChangeIsCheckedForUniquenessExcludingSelf(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(self::user());
        $this->repository->expects($this->once())
            ->method('usernameExists')->with('Maria.Lopez', self::USER_ID)->willReturn(false);
        $this->repository->expects($this->once())->method('update')
            ->with($this->callback(static fn (User $u): bool => $u->username === 'Maria.Lopez'));

        $this->service->update(self::USER_ID, self::updateDto(['username' => 'Maria.Lopez']), self::NOTARY, self::ACTOR_ID);
    }

    public function testUpdateTranslatesDuplicateUsernameRace(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(self::user());
        $this->repository->method('usernameExists')->willReturn(false);
        $this->repository->method('update')->willThrowException(new DuplicateUsernameException());

        $this->assertUserError(
            UserError::UsernameTaken,
            fn () => $this->service->update(self::USER_ID, self::updateDto(['username' => 'maria.nueva']), self::NOTARY, self::ACTOR_ID)
        );
    }

    public function testUpdateKeepsRawLegacyStatusWhenStatusIsNotSent(): void
    {
        $this->repository->method('findByIdAndNotary')->willReturn(self::user(7, 'fati', statusId: 0));
        $this->repository->expects($this->once())->method('update')
            ->with($this->callback(static fn (User $u): bool => $u->statusId === 0));

        $this->service->update(7, self::updateDto(['firstName' => 'Fátima']), self::NOTARY, self::ACTOR_ID);
    }

    public function testUpdateMergesSentFieldsOverExistingOnesAndKeepsTenantAndLastAccess(): void
    {
        $existing = new User(
            id: self::USER_ID,
            notaryId: self::NOTARY,
            username: 'maria.lopez',
            firstName: 'María',
            paternalLastName: 'López',
            maternalLastName: 'García',
            access: ['ESC', 'PAG'],
            statusId: 1,
            lastAccess: new \DateTimeImmutable('2026-11-10 08:30:00'),
        );
        $this->repository->method('findByIdAndNotary')->willReturn($existing);
        $this->repository->expects($this->once())->method('update')->with($this->callback(
            function (User $u) use ($existing): bool {
                return $u->id === self::USER_ID
                    && $u->notaryId === self::NOTARY
                    && $u->username === 'maria.lopez'
                    && $u->firstName === 'Ana'
                    && $u->paternalLastName === 'López'
                    && $u->maternalLastName === ''
                    && $u->access === ['PAG']
                    && $u->statusId === 1
                    && $u->lastAccess === $existing->lastAccess;
            }
        ));

        $this->service->update(
            self::USER_ID,
            self::updateDto(['firstName' => 'Ana', 'maternalLastName' => null, 'access' => ['PAG']]),
            self::NOTARY,
            self::ACTOR_ID
        );
    }
}
