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
use App\Module\PaymentTax\Repository\PaymentTaxRepository;
use App\Module\PaymentTax\Service\PaymentTaxService;
use App\Module\PaymentClient\Repository\PaymentClientRepository;
use App\Module\PaymentClient\Service\PaymentClientService;
use App\Module\PaymentProcedure\Repository\PaymentProcedureRepository;
use App\Module\PaymentProcedure\Service\PaymentProcedureService;
use App\Module\Notary\Repository\NotaryRepository;
use App\Module\Notary\Service\NotaryService;
use App\Module\Deed\Repository\DeedRepository;
use App\Module\Deed\Service\DeedService;

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

ServiceLocator::register(PaymentTaxRepository::class, function () {
    return new PaymentTaxRepository(Connection::getInstance());
});

ServiceLocator::register(PaymentTaxService::class, function () {
    return new PaymentTaxService(
        ServiceLocator::get(PaymentTaxRepository::class)
    );
});

ServiceLocator::register(PaymentProcedureRepository::class, function () {
    return new PaymentProcedureRepository(Connection::getInstance());
});

ServiceLocator::register(PaymentProcedureService::class, function () {
    return new PaymentProcedureService(
        ServiceLocator::get(PaymentProcedureRepository::class)
    );
});

ServiceLocator::register(PaymentClientRepository::class, function () {
    return new PaymentClientRepository(Connection::getInstance());
});

ServiceLocator::register(PaymentClientService::class, function () {
    return new PaymentClientService(
        ServiceLocator::get(PaymentClientRepository::class)
    );
});

ServiceLocator::register(NotaryRepository::class, function () {
    return new NotaryRepository(Connection::getInstance());
});

ServiceLocator::register(NotaryService::class, function () {
    return new NotaryService(
        ServiceLocator::get(NotaryRepository::class)
    );
});

ServiceLocator::register(DeedRepository::class, function () {
    return new DeedRepository(Connection::getInstance());
});

ServiceLocator::register(DeedService::class, function () {
    return new DeedService(
        ServiceLocator::get(DeedRepository::class)
    );
});
