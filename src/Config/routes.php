<?php

declare(strict_types=1);

use App\Core\Http\Router;
use App\Core\Middleware\AuthMiddleware;
use App\Module\Auth\Controller\AuthController;
use App\Module\Act\Controller\ActController;
use App\Module\Subdivision\Controller\SubdivisionController;

/**
 * API Routes Configuration
 *
 * All routes are prefixed with /api/v3
 */

/** @var Router $router */

// Register middlewares
$router->addMiddleware('auth', new AuthMiddleware());

// Health check
$router->get('/api/v3/health', function () {
    return [
        'status' => 'ok',
        'timestamp' => date('c'),
        'version' => '3.0.0'
    ];
});

// Auth routes (public - no middleware)
$router->post('/api/v3/auth/login', [AuthController::class, 'login']);
$router->post('/api/v3/auth/refresh', [AuthController::class, 'refresh']);

// Acts routes (protected)
$router->get('/api/v3/acts', [ActController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/acts/{id}', [ActController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/acts', [ActController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/acts/{id}', [ActController::class, 'update'])->withMiddleware('auth');

// Subdivisions routes (protected)
$router->get('/api/v3/subdivisions', [SubdivisionController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/subdivisions/{id}', [SubdivisionController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/subdivisions', [SubdivisionController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/subdivisions/{id}', [SubdivisionController::class, 'update'])->withMiddleware('auth');

// Protected routes (with auth middleware)
// $router->group(['middleware' => 'auth', 'prefix' => '/api/v3'], function (Router $router) {
//
//     // Deeds
//     $router->get('/deeds', [DeedController::class, 'index']);
//     $router->get('/deeds/{id}', [DeedController::class, 'show']);
//     $router->post('/deeds', [DeedController::class, 'store']);
//     $router->put('/deeds/{id}', [DeedController::class, 'update']);
//     $router->delete('/deeds/{id}', [DeedController::class, 'destroy']);
//
//     // Acts
//     $router->get('/acts', [ActController::class, 'index']);
//     $router->get('/acts/{id}', [ActController::class, 'show']);
//     $router->post('/acts', [ActController::class, 'store']);
//     $router->put('/acts/{id}', [ActController::class, 'update']);
//
//     // Deed Types
//     $router->get('/deed-types', [DeedTypeController::class, 'index']);
//     $router->get('/deed-types/{id}', [DeedTypeController::class, 'show']);
//     $router->post('/deed-types', [DeedTypeController::class, 'store']);
//     $router->put('/deed-types/{id}', [DeedTypeController::class, 'update']);
//
//     // Subdivisions
//     $router->get('/subdivisions', [SubdivisionController::class, 'index']);
//     $router->get('/subdivisions/{id}', [SubdivisionController::class, 'show']);
//     $router->post('/subdivisions', [SubdivisionController::class, 'store']);
//     $router->put('/subdivisions/{id}', [SubdivisionController::class, 'update']);
//
//     // Users
//     $router->get('/users', [UserController::class, 'index']);
//     $router->get('/users/{id}', [UserController::class, 'show']);
//     $router->post('/users', [UserController::class, 'store']);
//     $router->put('/users/{id}', [UserController::class, 'update']);
//
// });
