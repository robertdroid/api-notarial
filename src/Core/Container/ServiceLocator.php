<?php

declare(strict_types=1);

namespace App\Core\Container;

class ServiceLocator
{
    /** @var array<string, callable> */
    private static array $factories = [];

    /** @var array<string, object> */
    private static array $instances = [];

    public static function register(string $id, callable $factory): void
    {
        self::$factories[$id] = $factory;
    }

    public static function get(string $id): object
    {
        if (!isset(self::$instances[$id])) {
            if (!isset(self::$factories[$id])) {
                throw new \RuntimeException("Service not registered: {$id}");
            }
            self::$instances[$id] = (self::$factories[$id])();
        }

        return self::$instances[$id];
    }

    public static function has(string $id): bool
    {
        return isset(self::$factories[$id]);
    }

    public static function reset(): void
    {
        self::$instances = [];
    }
}
