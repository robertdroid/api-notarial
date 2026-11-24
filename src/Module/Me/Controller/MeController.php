<?php

declare(strict_types=1);

namespace App\Module\Me\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Me\DTO\MeResponse;
use App\Module\Me\Exception\MeException;
use App\Module\Me\Service\MeService;

final class MeController
{
    private MeService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(MeService::class);
    }

    /**
     * Identity comes only from the token: route params, query and body are ignored.
     */
    public function show(Request $request): Response
    {
        $userId = $request->getUserId();
        $notaryId = $request->getNotaryId();

        if ($userId === null || $notaryId === null) {
            return Response::unauthorized();
        }

        try {
            $profile = $this->service->getProfile($userId, $notaryId);
        } catch (MeException $e) {
            return match ($e->error->httpStatus()) {
                404 => Response::notFound($e->error->message()),
                default => Response::forbidden($e->error->message()),
            };
        }

        return Response::json(MeResponse::fromProfile($profile));
    }
}
