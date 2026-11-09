<?php

declare(strict_types=1);

namespace App\Module\Subdivision\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Subdivision\Service\SubdivisionService;
use App\Module\Subdivision\DTO\CreateSubdivisionRequest;
use App\Module\Subdivision\DTO\UpdateSubdivisionRequest;
use App\Module\Subdivision\DTO\SubdivisionResponse;

class SubdivisionController
{
    private SubdivisionService $subdivisionService;

    public function __construct()
    {
        $this->subdivisionService = ServiceLocator::get(SubdivisionService::class);
    }

    public function index(Request $request): Response
    {
        $notaryId = $request->getNotaryId();
        $status = $request->getQueryParam('status');
        $name = $request->getQueryParam('name');

        if ($status !== null && !in_array($status, ['1', '2'], true)) {
            return Response::badRequest('Invalid status value');
        }

        $subdivisions = $this->subdivisionService->getAll(
            $notaryId,
            $status !== null ? (int) $status : null,
            $name
        );

        return Response::json(SubdivisionResponse::fromCollection($subdivisions));
    }

    public function show(Request $request): Response
    {
        $id = $request->getRouteParam('id');

        if (!is_numeric($id)) {
            return Response::badRequest('Invalid ID format');
        }

        $subdivision = $this->subdivisionService->getById((int) $id, $request->getNotaryId());

        if ($subdivision === null) {
            return Response::notFound('Subdivision not found');
        }

        return Response::json(SubdivisionResponse::fromEntity($subdivision));
    }

    public function store(Request $request): Response
    {
        $dto = CreateSubdivisionRequest::fromArray($request->getBody());

        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        $result = $this->subdivisionService->create(
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

        $dto = UpdateSubdivisionRequest::fromArray($request->getBody());

        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        $result = $this->subdivisionService->update(
            (int) $id,
            $dto,
            $request->getNotaryId(),
            $request->getUserId()
        );

        if ($result === null) {
            return Response::notFound('Subdivision not found');
        }

        if ($result !== true) {
            return Response::badRequest($result);
        }

        return Response::noContent();
    }
}
