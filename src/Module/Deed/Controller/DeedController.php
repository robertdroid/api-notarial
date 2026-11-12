<?php

declare(strict_types=1);

namespace App\Module\Deed\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Deed\DTO\CreateDeedRequest;
use App\Module\Deed\DTO\DeedListItemResponse;
use App\Module\Deed\DTO\DeedListQuery;
use App\Module\Deed\DTO\DeedResponse;
use App\Module\Deed\DTO\UpdateDeedRequest;
use App\Module\Deed\Entity\DeedListItem;
use App\Module\Deed\Exception\DeedError;
use App\Module\Deed\Exception\DeedException;
use App\Module\Deed\Service\DeedService;

class DeedController
{
    use ParsesDeedIds;

    private DeedService $deedService;

    public function __construct()
    {
        $this->deedService = ServiceLocator::get(DeedService::class);
    }

    public function index(Request $request): Response
    {
        $query = DeedListQuery::fromQueryParams(
            $this->stringParam($request, 'page'),
            $this->stringParam($request, 'search'),
        );

        if (!$query->isValid()) {
            return Response::badRequest('Validation failed', $query->getErrors());
        }

        $result = $this->deedService->list($request->getNotaryId(), $query);

        return Response::json(
            $result->toArray(static fn (DeedListItem $deed): array => DeedListItemResponse::fromEntity($deed))
        );
    }

    public function show(Request $request): Response
    {
        $id = $this->parseId($request, 'id', DeedError::DeedNotFound);
        if ($id instanceof Response) {
            return $id;
        }

        try {
            $deed = $this->deedService->getById($id, $request->getNotaryId());
        } catch (DeedException $e) {
            return DeedErrorResponse::fromException($e);
        }

        return Response::json(DeedResponse::fromEntity($deed));
    }

    public function store(Request $request): Response
    {
        $dto = CreateDeedRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return DeedErrorResponse::validation($dto->getErrors());
        }

        try {
            $deed = $this->deedService->create($dto, $request->getNotaryId(), $request->getUserId());
        } catch (DeedException $e) {
            return DeedErrorResponse::fromException($e);
        }

        return Response::created(DeedResponse::fromEntity($deed));
    }

    public function update(Request $request): Response
    {
        $id = $this->parseId($request, 'id', DeedError::DeedNotFound);
        if ($id instanceof Response) {
            return $id;
        }

        $dto = UpdateDeedRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return DeedErrorResponse::validation($dto->getErrors());
        }

        try {
            $deed = $this->deedService->update($id, $dto, $request->getNotaryId(), $request->getUserId());
        } catch (DeedException $e) {
            return DeedErrorResponse::fromException($e);
        }

        return Response::json(DeedResponse::fromEntity($deed));
    }

    public function finish(Request $request): Response
    {
        $id = $this->parseId($request, 'id', DeedError::DeedNotFound);
        if ($id instanceof Response) {
            return $id;
        }

        try {
            $deed = $this->deedService->finish($id, $request->getNotaryId(), $request->getUserId());
        } catch (DeedException $e) {
            return DeedErrorResponse::fromException($e);
        }

        return Response::json(DeedResponse::fromEntity($deed));
    }

    private function stringParam(Request $request, string $key): ?string
    {
        $value = $request->getQueryParam($key);

        return is_string($value) ? $value : null;
    }
}
