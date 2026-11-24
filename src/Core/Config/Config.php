<?php

declare(strict_types=1);

namespace App\Core\Config;

class Config
{
    private static array $config = [];

    public static function load(): void
    {
        self::$config = $_ENV;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }

    public static function getRequired(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Missing required config: {$key}");
        }
        return (string) $value;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
