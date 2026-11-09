<?php

declare(strict_types=1);

namespace App\Module\Auth\Service;

use App\Core\Exception\AuthenticationException;
use App\Module\Auth\DTO\AuthUser;
use App\Module\Auth\DTO\TokenResponse;
use App\Module\Auth\Repository\UserAuthRepository;

class AuthService
{
    public function __construct(
        private readonly UserAuthRepository $userRepository,
        private readonly JwtService $jwtService,
    ) {}

    public function authenticate(string $username, string $password): TokenResponse
    {
        $user = $this->userRepository->findByUsername($username);

        if ($user === null) {
            throw new AuthenticationException();
        }

        if (!$user->isActive()) {
            throw new AuthenticationException();
        }

        if (!$this->verifyPassword($user, $password)) {
            throw new AuthenticationException();
        }

        return $this->generateTokens($user);
    }

    public function refreshTokens(string $refreshToken): TokenResponse
    {
        try {
            $payload = $this->jwtService->validateRefreshToken($refreshToken);
        } catch (\Exception $e) {
            throw new AuthenticationException('Token inválido o expirado');
        }

        $user = $this->userRepository->findActiveById((int) $payload->userId);

        if ($user === null) {
            throw new AuthenticationException();
        }

        return $this->generateTokens($user);
    }

    private function verifyPassword(AuthUser $user, string $password): bool
    {
        if ($user->hasPasswordHash()) {
            return password_verify($password, $user->passwordHash);
        }

        if ($user->passwordMd5 !== null && md5($password) === $user->passwordMd5) {
            $this->migratePassword($user->id, $password);
            return true;
        }

        return false;
    }

    private function migratePassword(int $userId, string $plainPassword): void
    {
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $this->userRepository->updatePasswordHash($userId, $hash);
    }

    private function generateTokens(AuthUser $user): TokenResponse
    {
        return new TokenResponse(
            accessToken: $this->jwtService->generateAccessToken($user),
            refreshToken: $this->jwtService->generateRefreshToken($user->id, $user->notaryId),
            expiresIn: $this->jwtService->getAccessExpiry(),
        );
    }
}
