<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Auth\Service\JwtService;

class AuthMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $token = $request->getBearerToken();

        if ($token === null) {
            return Response::unauthorized('Token no proporcionado');
        }

        try {
            $jwtService = ServiceLocator::get(JwtService::class);
            $payload = $jwtService->validateToken($token);

            if (isset($payload->type) && $payload->type === 'refresh') {
                return Response::unauthorized('Token inválido');
            }

            $request->setAuthUser([
                'userId' => $payload->userId,
                'notaryId' => $payload->notaryId,
                'features' => $payload->features ?? [],
            ]);

        } catch (\Exception $e) {
            return Response::unauthorized('Token inválido o expirado');
        }

        return $next($request);
    }
}
