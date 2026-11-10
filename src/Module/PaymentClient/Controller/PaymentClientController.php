<?php

declare(strict_types=1);

namespace App\Module\PaymentClient\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\PaymentClient\DTO\CreatePaymentClientRequest;
use App\Module\PaymentClient\DTO\PaymentClientResponse;
use App\Module\PaymentClient\DTO\UpdatePaymentClientRequest;
use App\Module\PaymentClient\Service\PaymentClientService;

final class PaymentClientController
{
    private const NOT_FOUND = 'Client not found';

    private PaymentClientService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(PaymentClientService::class);
    }

    public function index(Request $request): Response
    {
        $status = $request->getQueryParam('status');
        $name = $request->getQueryParam('name');

        if ($status !== null && !in_array($status, ['1', '2'], true)) {
            return Response::badRequest('Invalid status value');
        }

        $clients = $this->service->getAll(
            $request->getNotaryId(),
            $status !== null ? (int) $status : null,
            is_string($name) ? trim($name) : null,
        );

        return Response::json(PaymentClientResponse::fromCollection($clients));
    }

    public function show(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        $client = $this->service->getById($id, $request->getNotaryId());

        return $client === null
            ? Response::notFound(self::NOT_FOUND)
            : Response::json(PaymentClientResponse::fromEntity($client));
    }

    public function store(Request $request): Response
    {
        $dto = CreatePaymentClientRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        $this->service->create($dto, $request->getNotaryId());

        return Response::created();
    }

    public function update(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        $dto = UpdatePaymentClientRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        return $this->service->update($id, $dto, $request->getNotaryId())
            ? Response::noContent()
            : Response::notFound(self::NOT_FOUND);
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
