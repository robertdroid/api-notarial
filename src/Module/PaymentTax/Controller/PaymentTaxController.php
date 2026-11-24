<?php

declare(strict_types=1);

namespace App\Module\PaymentTax\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\PaymentTax\DTO\CreatePaymentTaxRequest;
use App\Module\PaymentTax\DTO\PaymentTaxResponse;
use App\Module\PaymentTax\DTO\UpdatePaymentTaxRequest;
use App\Module\PaymentTax\Service\PaymentTaxService;

final class PaymentTaxController
{
    private const NOT_FOUND = 'Tax not found';

    private PaymentTaxService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(PaymentTaxService::class);
    }

    public function index(Request $request): Response
    {
        $status = $request->getQueryParam('status');
        $name = $request->getQueryParam('name');

        if ($status !== null && !in_array($status, ['1', '2'], true)) {
            return Response::badRequest('Invalid status value');
        }

        $taxes = $this->service->getAll(
            $request->getNotaryId(),
            $status !== null ? (int) $status : null,
            is_string($name) ? trim($name) : null,
        );

        return Response::json(PaymentTaxResponse::fromCollection($taxes));
    }

    public function show(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        $tax = $this->service->getById($id, $request->getNotaryId());

        return $tax === null
            ? Response::notFound(self::NOT_FOUND)
            : Response::json(PaymentTaxResponse::fromEntity($tax));
    }

    public function store(Request $request): Response
    {
        $dto = CreatePaymentTaxRequest::fromArray($request->getBody());
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

        $dto = UpdatePaymentTaxRequest::fromArray($request->getBody());
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
