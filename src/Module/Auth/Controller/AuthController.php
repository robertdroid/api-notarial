<?php

declare(strict_types=1);

namespace App\Module\Auth\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Exception\AuthenticationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Auth\Service\AuthService;

class AuthController
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = ServiceLocator::get(AuthService::class);
    }

    public function login(Request $request): Response
    {
        $username = $request->getBodyParam('usuario');
        $password = $request->getBodyParam('contrasenia');

        if (empty($username) || empty($password)) {
            return Response::badRequest('Usuario y contraseña son requeridos');
        }

        try {
            $tokens = $this->authService->authenticate($username, $password);
            return Response::json($tokens->toArray());
        } catch (AuthenticationException $e) {
            return Response::unauthorized('Credenciales inválidas');
        }
    }

    public function refresh(Request $request): Response
    {
        $refreshToken = $request->getBodyParam('refreshToken');

        if (empty($refreshToken)) {
            return Response::badRequest('Refresh token es requerido');
        }

        try {
            $tokens = $this->authService->refreshTokens($refreshToken);
            return Response::json($tokens->toArray());
        } catch (AuthenticationException $e) {
            return Response::unauthorized($e->getMessage() ?: 'Token inválido o expirado');
        }
    }
}
