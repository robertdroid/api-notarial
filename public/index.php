<?php

declare(strict_types=1);

define('APP_START', microtime(true));
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/src/bootstrap.php';

use App\Core\Http\Router;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Exception\HttpException;
use App\Core\Config\Config;

date_default_timezone_set(Config::get('APP_TIMEZONE', 'America/Mexico_City'));

header('Content-Type: application/json; charset=utf-8');

try {
    $request = Request::createFromGlobals();
    $router = new Router();

    require_once BASE_PATH . '/src/Config/routes.php';

    $response = $router->dispatch($request);
    $response->send();

} catch (HttpException $e) {
    $response = Response::json([
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ], $e->getStatusCode());
    $response->send();

} catch (Throwable $e) {
    $response = Response::json([
        'error' => 'Internal Server Error',
        'message' => Config::getBool('APP_DEBUG', false) ? $e->getMessage() : null
    ], 500);
    $response->send();
}
