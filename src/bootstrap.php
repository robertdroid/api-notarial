<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Container\ServiceLocator;
use App\Module\Auth\Service\JwtService;
use App\Module\Auth\Service\AuthService;
use App\Module\Auth\Repository\UserAuthRepository;
use App\Module\Act\Repository\ActRepository;
use App\Module\Act\Service\ActService;
use App\Module\Subdivision\Repository\SubdivisionRepository;
use App\Module\Subdivision\Service\SubdivisionService;
use App\Module\DeedType\Repository\DeedTypeRepository;
use App\Module\DeedType\Service\DeedTypeService;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->load();

Config::load();

ServiceLocator::register(JwtService::class, function () {
    return new JwtService();
});

ServiceLocator::register(UserAuthRepository::class, function () {
    return new UserAuthRepository(Connection::getInstance());
});

ServiceLocator::register(AuthService::class, function () {
    return new AuthService(
        ServiceLocator::get(UserAuthRepository::class),
        ServiceLocator::get(JwtService::class)
    );
});

ServiceLocator::register(ActRepository::class, function () {
    return new ActRepository(Connection::getInstance());
});

ServiceLocator::register(ActService::class, function () {
    return new ActService(
        ServiceLocator::get(ActRepository::class)
    );
});

ServiceLocator::register(SubdivisionRepository::class, function () {
    return new SubdivisionRepository(Connection::getInstance());
});

ServiceLocator::register(SubdivisionService::class, function () {
    return new SubdivisionService(
        ServiceLocator::get(SubdivisionRepository::class)
    );
});

ServiceLocator::register(DeedTypeRepository::class, function () {
    return new DeedTypeRepository(Connection::getInstance());
});

ServiceLocator::register(DeedTypeService::class, function () {
    return new DeedTypeService(
        ServiceLocator::get(DeedTypeRepository::class)
    );
});
