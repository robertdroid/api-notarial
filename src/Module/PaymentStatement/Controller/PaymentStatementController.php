<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\PaymentStatement\DTO\StatementQuery;
use App\Module\PaymentStatement\DTO\StatementResponse;
use App\Module\PaymentStatement\Service\PaymentStatementService;

final class PaymentStatementController
{
    private const NOT_FOUND = 'Client not found';

    private PaymentStatementService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(PaymentStatementService::class);
    }

    public function show(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        $query = StatementQuery::fromArray($request->getQueryParams());
        if (!$query->isValid()) {
            return Response::badRequest('Validation failed', $query->getErrors());
        }

        $statement = $this->service->build($id, $request->getNotaryId(), $query);

        return $statement === null
            ? Response::notFound(self::NOT_FOUND)
            : Response::json(StatementResponse::fromEntity($statement));
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
