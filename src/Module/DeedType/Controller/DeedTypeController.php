<?php

declare(strict_types=1);

namespace App\Module\DeedType\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\DeedType\Service\DeedTypeService;
use App\Module\DeedType\DTO\CreateDeedTypeRequest;
use App\Module\DeedType\DTO\UpdateDeedTypeRequest;
use App\Module\DeedType\DTO\DeedTypeResponse;

class DeedTypeController
{
    private DeedTypeService $deedTypeService;

    public function __construct()
    {
        $this->deedTypeService = ServiceLocator::get(DeedTypeService::class);
    }

    public function index(Request $request): Response
    {
        $notaryId = $request->getNotaryId();
        $status = $request->getQueryParam('status');
        $name = $request->getQueryParam('name');

        if ($status !== null && !in_array($status, ['1', '2'], true)) {
            return Response::badRequest('Invalid status value');
        }

        $deedTypes = $this->deedTypeService->getAll(
            $notaryId,
            $status !== null ? (int) $status : null,
            $name
        );

        return Response::json(DeedTypeResponse::fromCollection($deedTypes));
    }

    public function show(Request $request): Response
    {
        $id = $request->getRouteParam('id');

        if (!is_numeric($id)) {
            return Response::badRequest('Invalid ID format');
        }

        $deedType = $this->deedTypeService->getById((int) $id, $request->getNotaryId());

        if ($deedType === null) {
            return Response::notFound('Deed type not found');
        }

        return Response::json(DeedTypeResponse::fromEntity($deedType));
    }

    public function store(Request $request): Response
    {
        $dto = CreateDeedTypeRequest::fromArray($request->getBody());

        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        $result = $this->deedTypeService->create(
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

        $dto = UpdateDeedTypeRequest::fromArray($request->getBody());

        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        $result = $this->deedTypeService->update(
            (int) $id,
            $dto,
            $request->getNotaryId(),
            $request->getUserId()
        );

        if ($result === null) {
            return Response::notFound('Deed type not found');
        }

        if ($result !== true) {
            return Response::badRequest($result);
        }

        return Response::noContent();
    }
}
