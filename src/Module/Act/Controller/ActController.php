<?php

declare(strict_types=1);

namespace App\Module\Act\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Act\Service\ActService;
use App\Module\Act\DTO\CreateActRequest;
use App\Module\Act\DTO\UpdateActRequest;
use App\Module\Act\DTO\ActResponse;

class ActController
{
    private ActService $actService;

    public function __construct()
    {
        $this->actService = ServiceLocator::get(ActService::class);
    }

    public function index(Request $request): Response
    {
        $notaryId = $request->getNotaryId();
        $status = $request->getQueryParam('status');
        $name = $request->getQueryParam('name');

        if ($status !== null && !in_array($status, ['1', '2'], true)) {
            return Response::badRequest('Invalid status value');
        }

        $acts = $this->actService->getAll(
            $notaryId,
            $status !== null ? (int) $status : null,
            $name
        );

        return Response::json(ActResponse::fromCollection($acts));
    }

    public function show(Request $request): Response
    {
        $id = $request->getRouteParam('id');

        if (!is_numeric($id)) {
            return Response::badRequest('Invalid ID format');
        }

        $act = $this->actService->getById((int) $id, $request->getNotaryId());

        if ($act === null) {
            return Response::notFound('Act not found');
        }

        return Response::json(ActResponse::fromEntity($act));
    }

    public function store(Request $request): Response
    {
        $dto = CreateActRequest::fromArray($request->getBody());

        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        $result = $this->actService->create(
            $dto,
            $request->getNotaryId(),
            $request->getUserId()
        );

        if ($result !== true) {
            return Response::badRequest($result);
        }

        return Response::created();
    }

    public function update(Request $request): Response
    {
        $id = $request->getRouteParam('id');

        if (!is_numeric($id)) {
            return Response::badRequest('Invalid ID format');
        }

        $dto = UpdateActRequest::fromArray($request->getBody());

        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        $result = $this->actService->update(
            (int) $id,
            $dto,
            $request->getNotaryId(),
            $request->getUserId()
        );

        if ($result === null) {
            return Response::notFound('Act not found');
        }

        if ($result !== true) {
            return Response::badRequest($result);
        }

        return Response::noContent();
    }
}
