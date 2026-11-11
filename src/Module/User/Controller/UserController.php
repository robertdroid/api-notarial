<?php

declare(strict_types=1);

namespace App\Module\User\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\User\DTO\CreateUserRequest;
use App\Module\User\DTO\UpdateUserRequest;
use App\Module\User\DTO\UserResponse;
use App\Module\User\Exception\UserError;
use App\Module\User\Exception\UserException;
use App\Module\User\Service\UserService;

final class UserController
{
    private const NOT_FOUND = 'User not found';

    private UserService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(UserService::class);
    }

    public function index(Request $request): Response
    {
        $status = $request->getQueryParam('status');

        if ($status !== null && !in_array($status, ['1', '2'], true)) {
            return Response::badRequest('Invalid status value');
        }

        $users = $this->service->getAll(
            $request->getNotaryId(),
            $status !== null ? (int) $status : null,
        );

        return Response::json(UserResponse::fromCollection($users));
    }

    public function show(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        try {
            $user = $this->service->getById($id, $request->getNotaryId());
        } catch (UserException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::json(UserResponse::fromEntity($user));
    }

    public function store(Request $request): Response
    {
        $dto = CreateUserRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        try {
            $user = $this->service->create($dto, $request->getNotaryId());
        } catch (UserException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::created(UserResponse::fromEntity($user));
    }

    public function update(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        $dto = UpdateUserRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        try {
            $user = $this->service->update($id, $dto, $request->getNotaryId(), $request->getUserId());
        } catch (UserException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::json(UserResponse::fromEntity($user));
    }

    private function errorResponse(UserError $error): Response
    {
        return match ($error) {
            UserError::InvalidUsername => Response::badRequest('Validation failed', ['username' => $error->message()]),
            UserError::CannotDeactivateSelf => Response::forbidden($error->message()),
            UserError::UserNotFound => Response::notFound($error->message()),
            UserError::UsernameTaken => Response::conflict($error->message()),
        };
    }

    private function parseId(Request $request): int|Response
    {
        $id = filter_var($request->getRouteParam('id'), FILTER_VALIDATE_INT);

        if ($id === false) {
            return Response::badRequest('Invalid ID format');
        }

        if ($id <= 0) {
            return Response::notFound(self::NOT_FOUND);
        }

        return $id;
    }
}
