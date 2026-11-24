<?php

declare(strict_types=1);

namespace Tests\Module\Auth\Service;

use App\Core\Config\Config;
use App\Module\Auth\DTO\AuthUser;
use App\Module\Auth\Service\JwtService;
use PHPUnit\Framework\TestCase;

final class JwtServiceTest extends TestCase
{
    private JwtService $jwtService;

    protected function setUp(): void
    {
        $_ENV['JWT_SECRET'] = 'test-secret-key-at-least-32-characters-long';
        $_ENV['JWT_EXPIRATION'] = '7200';
        $_ENV['JWT_REFRESH_EXPIRATION'] = '86400';
        $_ENV['JWT_ISSUER'] = 'test-issuer';
        $_ENV['JWT_AUDIENCE'] = 'test-audience';

        Config::load();
        $this->jwtService = new JwtService();
    }

    public function testGenerateAccessTokenReturnsValidJwt(): void
    {
        $user = $this->createAuthUser();

        $token = $this->jwtService->generateAccessToken($user);

        $this->assertNotEmpty($token);
        $this->assertCount(3, explode('.', $token));
    }

    public function testGenerateRefreshTokenIncludesTypeRefresh(): void
    {
        $token = $this->jwtService->generateRefreshToken(1, 1);
        $payload = $this->jwtService->validateToken($token);

        $this->assertEquals('refresh', $payload->type);
    }

    public function testValidateTokenReturnsCorrectPayload(): void
    {
        $user = $this->createAuthUser();
        $token = $this->jwtService->generateAccessToken($user);

        $payload = $this->jwtService->validateToken($token);

        $this->assertEquals($user->id, $payload->userId);
        $this->assertEquals($user->notaryId, $payload->notaryId);
        $this->assertEquals($user->getFeatures(), $payload->features);
    }

    public function testValidateRefreshTokenRejectsAccessToken(): void
    {
        $user = $this->createAuthUser();
        $accessToken = $this->jwtService->generateAccessToken($user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid token type');

        $this->jwtService->validateRefreshToken($accessToken);
    }

    public function testValidateRefreshTokenAcceptsRefreshToken(): void
    {
        $refreshToken = $this->jwtService->generateRefreshToken(1, 1);

        $payload = $this->jwtService->validateRefreshToken($refreshToken);

        $this->assertEquals(1, $payload->userId);
        $this->assertEquals(1, $payload->notaryId);
        $this->assertEquals('refresh', $payload->type);
    }

    public function testGetAccessExpiryReturnsConfiguredValue(): void
    {
        $expiry = $this->jwtService->getAccessExpiry();

        $this->assertEquals(7200, $expiry);
    }

    private function createAuthUser(): AuthUser
    {
        return new AuthUser(
            id: 1,
            username: 'testuser',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            passwordMd5: null,
            notaryId: 1,
            userStatus: 1,
            notaryStatus: 1,
            userAccess: 'deeds,acts,users',
        );
    }
}
