<?php

declare(strict_types=1);

namespace App\Module\User\Service;

use App\Module\User\DTO\CreateUserRequest;
use App\Module\User\DTO\UpdateUserRequest;
use App\Module\User\DTO\UserFieldRules;
use App\Module\User\Entity\User;
use App\Module\User\Exception\DuplicateUsernameException;
use App\Module\User\Exception\UserError;
use App\Module\User\Exception\UserException;
use App\Module\User\Repository\UserRepository;

final class UserService
{
    private const BCRYPT_COST = 10;

    public function __construct(
        private readonly UserRepository $repository
    ) {
    }

    /**
     * @return User[]
     */
    public function getAll(int $notaryId, ?int $status): array
    {
        return $this->repository->findAllByNotary($notaryId, $status);
    }

    /**
     * @throws UserException UserNotFound
     */
    public function getById(int $id, int $notaryId): User
    {
        return $this->repository->findByIdAndNotary($id, $notaryId)
            ?? throw new UserException(UserError::UserNotFound);
    }

    /**
     * @throws UserException UsernameTaken
     */
    public function create(CreateUserRequest $dto, int $notaryId): User
    {
        if ($this->repository->usernameExists($dto->username)) {
            throw new UserException(UserError::UsernameTaken);
        }

        $hash = password_hash($dto->password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);

        try {
            $id = $this->repository->create($notaryId, $dto, $hash);
        } catch (DuplicateUsernameException) {
            throw new UserException(UserError::UsernameTaken);
        }

        return $this->getById($id, $notaryId);
    }

    /**
     * @throws UserException UserNotFound, CannotDeactivateSelf, InvalidUsername, UsernameTaken
     */
    public function update(int $id, UpdateUserRequest $dto, int $notaryId, int $actorUserId): User
    {
        $existing = $this->getById($id, $notaryId);

        if ($id === $actorUserId && $dto->status === User::STATUS_INACTIVE) {
            throw new UserException(UserError::CannotDeactivateSelf);
        }

        // Legacy usernames may not match the current rule; only validate when it actually changes (ADR-004).
        if ($dto->username !== null && $dto->username !== $existing->username) {
            if (UserFieldRules::username($dto->username) !== null) {
                throw new UserException(UserError::InvalidUsername);
            }

            if ($this->repository->usernameExists($dto->username, $id)) {
                throw new UserException(UserError::UsernameTaken);
            }
        }

        try {
            $this->repository->update(new User(
                id: $id,
                notaryId: $notaryId,
                username: $dto->username ?? $existing->username,
                firstName: $dto->firstName ?? $existing->firstName,
                paternalLastName: $dto->paternalLastName ?? $existing->paternalLastName,
                maternalLastName: $dto->maternalLastName ?? $existing->maternalLastName,
                access: $dto->access ?? $existing->access,
                statusId: $dto->status ?? $existing->statusId,
                lastAccess: $existing->lastAccess,
            ));
        } catch (DuplicateUsernameException) {
            throw new UserException(UserError::UsernameTaken);
        }

        return $this->getById($id, $notaryId);
    }
}
