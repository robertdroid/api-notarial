<?php

declare(strict_types=1);

namespace App\Module\Auth\Service;

use App\Core\Config\Config;
use App\Module\Auth\DTO\AuthUser;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService
{
    private string $secret;
    private string $issuer;
    private string $audience;
    private int $accessExpiry;
    private int $refreshExpiry;

    public function __construct()
    {
        $this->secret = Config::getRequired('JWT_SECRET');
        $this->issuer = Config::get('JWT_ISSUER', 'security@devrob');
        $this->audience = Config::get('JWT_AUDIENCE', 'apps@devrob');
        $this->accessExpiry = Config::getInt('JWT_EXPIRATION', 7200);
        $this->refreshExpiry = Config::getInt('JWT_REFRESH_EXPIRATION', 86400);
    }

    public function generateAccessToken(AuthUser $user): string
    {
        $now = time();

        $payload = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $now,
            'exp' => $now + $this->accessExpiry,
            'userId' => $user->id,
            'notaryId' => $user->notaryId,
            'features' => $user->getFeatures(),
        ];

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    public function generateRefreshToken(int $userId, int $notaryId): string
    {
        $now = time();

        $payload = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $now,
            'exp' => $now + $this->refreshExpiry,
            'userId' => $userId,
            'notaryId' => $notaryId,
            'type' => 'refresh',
        ];

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    public function validateToken(string $token): object
    {
        return JWT::decode($token, new Key($this->secret, 'HS256'));
    }

    public function validateRefreshToken(string $token): object
    {
        $payload = $this->validateToken($token);

        if (!isset($payload->type) || $payload->type !== 'refresh') {
            throw new \InvalidArgumentException('Invalid token type');
        }

        return $payload;
    }

    public function getAccessExpiry(): int
    {
        return $this->accessExpiry;
    }
}
