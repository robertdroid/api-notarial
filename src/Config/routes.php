<?php

declare(strict_types=1);

use App\Core\Http\Router;
use App\Core\Middleware\AuthMiddleware;
use App\Module\Auth\Controller\AuthController;
use App\Module\Act\Controller\ActController;
use App\Module\Subdivision\Controller\SubdivisionController;
use App\Module\DeedType\Controller\DeedTypeController;
use App\Module\PaymentTax\Controller\PaymentTaxController;
use App\Module\PaymentClient\Controller\PaymentClientController;
use App\Module\PaymentProcedure\Controller\PaymentProcedureController;
use App\Module\PaymentProcedureTax\Controller\PaymentProcedureTaxController;
use App\Module\PaymentTransaction\Controller\PaymentTransactionController;
use App\Module\User\Controller\UserController;
use App\Module\Notary\Controller\NotaryController;
use App\Module\Deed\Controller\DeedController;
use App\Module\Deed\Controller\ParticipantController;
use App\Module\Deed\Controller\RegistrationController;

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

// Deed Types routes (protected)
$router->get('/api/v3/deed-types', [DeedTypeController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/deed-types/{id}', [DeedTypeController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/deed-types', [DeedTypeController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/deed-types/{id}', [DeedTypeController::class, 'update'])->withMiddleware('auth');

// Payment Taxes routes (protected)
$router->get('/api/v3/payment-taxes', [PaymentTaxController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/payment-taxes/{id}', [PaymentTaxController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/payment-taxes', [PaymentTaxController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/payment-taxes/{id}', [PaymentTaxController::class, 'update'])->withMiddleware('auth');

// Payment Procedures routes (protected)
$router->get('/api/v3/payment-procedures', [PaymentProcedureController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/payment-procedures/{id}', [PaymentProcedureController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/payment-procedures', [PaymentProcedureController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/payment-procedures/{id}', [PaymentProcedureController::class, 'update'])->withMiddleware('auth');
$router->get('/api/v3/payment-procedures/{id}/taxes', [PaymentProcedureTaxController::class, 'index'])->withMiddleware('auth');
$router->post('/api/v3/payment-procedures/{id}/taxes', [PaymentProcedureTaxController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/payment-procedures/{id}/taxes/{taxId}', [PaymentProcedureTaxController::class, 'update'])->withMiddleware('auth');
$router->delete('/api/v3/payment-procedures/{id}/taxes/{taxId}', [PaymentProcedureTaxController::class, 'destroy'])->withMiddleware('auth');

// Payment Clients routes (protected)
$router->get('/api/v3/payment-clients', [PaymentClientController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/payment-clients/{id}', [PaymentClientController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/payment-clients', [PaymentClientController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/payment-clients/{id}', [PaymentClientController::class, 'update'])->withMiddleware('auth');

// Payment Transactions routes (protected)
$router->get('/api/v3/payment-transactions', [PaymentTransactionController::class, 'showByUuid'])->withMiddleware('auth');
$router->get('/api/v3/payment-transactions/{id}', [PaymentTransactionController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/payment-transactions', [PaymentTransactionController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/payment-transactions/{id}', [PaymentTransactionController::class, 'update'])->withMiddleware('auth');

// Users routes (protected)
$router->get('/api/v3/users', [UserController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/users/{id}', [UserController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/users', [UserController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/users/{id}', [UserController::class, 'update'])->withMiddleware('auth');

// Notary profile routes (protected)
$router->get('/api/v3/notary', [NotaryController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/notary', [NotaryController::class, 'update'])->withMiddleware('auth');
$router->get('/api/v3/notary/logo', [NotaryController::class, 'logo'])->withMiddleware('auth');

// Deeds routes (protected)
$router->get('/api/v3/deeds', [DeedController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/deeds/{id}', [DeedController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/deeds', [DeedController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/deeds/{id}', [DeedController::class, 'update'])->withMiddleware('auth');
$router->post('/api/v3/deeds/{id}/finish', [DeedController::class, 'finish'])->withMiddleware('auth');

$router->get('/api/v3/deeds/{id}/participants', [ParticipantController::class, 'index'])->withMiddleware('auth');
$router->post('/api/v3/deeds/{id}/participants', [ParticipantController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/deeds/{id}/participants/{participantId}', [ParticipantController::class, 'update'])->withMiddleware('auth');
$router->delete('/api/v3/deeds/{id}/participants/{participantId}', [ParticipantController::class, 'destroy'])->withMiddleware('auth');

$router->get('/api/v3/deeds/{id}/registrations', [RegistrationController::class, 'index'])->withMiddleware('auth');
$router->post('/api/v3/deeds/{id}/registrations', [RegistrationController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/deeds/{id}/registrations/{registrationId}', [RegistrationController::class, 'update'])->withMiddleware('auth');
$router->delete('/api/v3/deeds/{id}/registrations/{registrationId}', [RegistrationController::class, 'destroy'])->withMiddleware('auth');

// Protected routes (with auth middleware)
// $router->group(['middleware' => 'auth', 'prefix' => '/api/v3'], function (Router $router) {
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
// });
