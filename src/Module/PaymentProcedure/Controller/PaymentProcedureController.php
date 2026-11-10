<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedure\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\PaymentProcedure\DTO\CreatePaymentProcedureRequest;
use App\Module\PaymentProcedure\DTO\PaymentProcedureResponse;
use App\Module\PaymentProcedure\DTO\UpdatePaymentProcedureRequest;
use App\Module\PaymentProcedure\Service\PaymentProcedureService;

final class PaymentProcedureController
{
    private const NOT_FOUND = 'Procedure not found';

    private PaymentProcedureService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(PaymentProcedureService::class);
    }

    public function index(Request $request): Response
    {
        $status = $request->getQueryParam('status');
        $name = $request->getQueryParam('name');

        if ($status !== null && !in_array($status, ['1', '2'], true)) {
            return Response::badRequest('Invalid status value');
        }

        $procedures = $this->service->getAll(
            $request->getNotaryId(),
            $status !== null ? (int) $status : null,
            is_string($name) ? trim($name) : null,
        );

        return Response::json(PaymentProcedureResponse::fromCollection($procedures));
    }

    public function show(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        $procedure = $this->service->getById($id, $request->getNotaryId());

        return $procedure === null
            ? Response::notFound(self::NOT_FOUND)
            : Response::json(PaymentProcedureResponse::fromEntity($procedure));
    }

    public function store(Request $request): Response
    {
        $dto = CreatePaymentProcedureRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        $result = $this->service->create($dto, $request->getNotaryId());

        return $result === true ? Response::created() : Response::badRequest($result);
    }

    public function update(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        $dto = UpdatePaymentProcedureRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        $result = $this->service->update($id, $dto, $request->getNotaryId());

        return match (true) {
            $result === null => Response::notFound(self::NOT_FOUND),
            $result === true => Response::noContent(),
            default => Response::badRequest($result),
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
