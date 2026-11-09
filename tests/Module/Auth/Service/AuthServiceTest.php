<?php

declare(strict_types=1);

namespace Tests\Module\Auth\Service;

use App\Core\Config\Config;
use App\Core\Exception\AuthenticationException;
use App\Module\Auth\DTO\AuthUser;
use App\Module\Auth\DTO\TokenResponse;
use App\Module\Auth\Repository\UserAuthRepository;
use App\Module\Auth\Service\AuthService;
use App\Module\Auth\Service\JwtService;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private AuthService $authService;
    private UserAuthRepository $userRepository;
    private JwtService $jwtService;

    protected function setUp(): void
    {
        $_ENV['JWT_SECRET'] = 'test-secret-key-at-least-32-characters-long';
        $_ENV['JWT_EXPIRATION'] = '7200';
        $_ENV['JWT_REFRESH_EXPIRATION'] = '86400';
        Config::load();

        $this->userRepository = $this->createMock(UserAuthRepository::class);
        $this->jwtService = new JwtService();
        $this->authService = new AuthService($this->userRepository, $this->jwtService);
    }

    public function testAuthenticateWithValidCredentialsReturnsTokens(): void
    {
        $user = $this->createActiveUserWithHash('password123');

        $this->userRepository
            ->method('findByUsername')
            ->with('testuser')
            ->willReturn($user);

        $result = $this->authService->authenticate('testuser', 'password123');

        $this->assertInstanceOf(TokenResponse::class, $result);
        $this->assertNotEmpty($result->accessToken);
        $this->assertNotEmpty($result->refreshToken);
        $this->assertEquals(7200, $result->expiresIn);
    }

    public function testAuthenticateWithInvalidUsernameThrowsException(): void
    {
        $this->userRepository
            ->method('findByUsername')
            ->willReturn(null);

        $this->expectException(AuthenticationException::class);

        $this->authService->authenticate('nonexistent', 'password');
    }

    public function testAuthenticateWithInvalidPasswordThrowsException(): void
    {
        $user = $this->createActiveUserWithHash('correctpassword');

        $this->userRepository
            ->method('findByUsername')
            ->willReturn($user);

        $this->expectException(AuthenticationException::class);

        $this->authService->authenticate('testuser', 'wrongpassword');
    }

    public function testAuthenticateWithInactiveUserThrowsException(): void
    {
        $user = new AuthUser(
            id: 1,
            username: 'testuser',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            passwordMd5: null,
            notaryId: 1,
            userStatus: 2,
            notaryStatus: 1,
            userAccess: null,
        );

        $this->userRepository
            ->method('findByUsername')
            ->willReturn($user);

        $this->expectException(AuthenticationException::class);

        $this->authService->authenticate('testuser', 'password');
    }

    public function testAuthenticateWithInactiveNotaryThrowsException(): void
    {
        $user = new AuthUser(
            id: 1,
            username: 'testuser',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            passwordMd5: null,
            notaryId: 1,
            userStatus: 1,
            notaryStatus: 2,
            userAccess: null,
        );

        $this->userRepository
            ->method('findByUsername')
            ->willReturn($user);

        $this->expectException(AuthenticationException::class);

        $this->authService->authenticate('testuser', 'password');
    }

    public function testAuthenticateWithMd5PasswordMigratesHash(): void
    {
        $plainPassword = 'legacypassword';
        $user = new AuthUser(
            id: 1,
            username: 'testuser',
            passwordHash: null,
            passwordMd5: md5($plainPassword),
            notaryId: 1,
            userStatus: 1,
            notaryStatus: 1,
            userAccess: null,
        );

        $this->userRepository
            ->method('findByUsername')
            ->willReturn($user);

        $this->userRepository
            ->expects($this->once())
            ->method('updatePasswordHash')
            ->with(
                $this->equalTo(1),
                $this->callback(fn($hash) => password_verify($plainPassword, $hash))
            );

        $result = $this->authService->authenticate('testuser', $plainPassword);

        $this->assertInstanceOf(TokenResponse::class, $result);
    }

    public function testRefreshTokensWithValidTokenReturnsNewTokens(): void
    {
        $user = $this->createActiveUserWithHash('password');
        $refreshToken = $this->jwtService->generateRefreshToken(1, 1);

        $this->userRepository
            ->method('findActiveById')
            ->with(1)
            ->willReturn($user);

        $result = $this->authService->refreshTokens($refreshToken);

        $this->assertInstanceOf(TokenResponse::class, $result);
        $this->assertNotEmpty($result->accessToken);
    }

    public function testRefreshTokensWithInvalidTokenThrowsException(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->authService->refreshTokens('invalid.token.here');
    }

    public function testRefreshTokensWithAccessTokenThrowsException(): void
    {
        $user = $this->createActiveUserWithHash('password');
        $accessToken = $this->jwtService->generateAccessToken($user);

        $this->expectException(AuthenticationException::class);

        $this->authService->refreshTokens($accessToken);
    }

    private function createActiveUserWithHash(string $password): AuthUser
    {
        return new AuthUser(
            id: 1,
            username: 'testuser',
            passwordHash: password_hash($password, PASSWORD_DEFAULT),
            passwordMd5: null,
            notaryId: 1,
            userStatus: 1,
            notaryStatus: 1,
            userAccess: 'deeds,acts',
        );
    }
}
