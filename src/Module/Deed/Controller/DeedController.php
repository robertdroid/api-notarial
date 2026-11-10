<?php

declare(strict_types=1);

namespace App\Module\Deed\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Deed\DTO\DeedListItemResponse;
use App\Module\Deed\DTO\DeedListQuery;
use App\Module\Deed\Entity\DeedListItem;
use App\Module\Deed\Service\DeedService;

class DeedController
{
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

    private function stringParam(Request $request, string $key): ?string
    {
        $value = $request->getQueryParam($key);

        return is_string($value) ? $value : null;
    }
}
