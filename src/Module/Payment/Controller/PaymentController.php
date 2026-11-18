<?php

declare(strict_types=1);

namespace App\Module\Payment\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Payment\DTO\CreatePaymentRequest;
use App\Module\Payment\DTO\PaymentFieldRules;
use App\Module\Payment\DTO\PaymentResponse;
use App\Module\Payment\DTO\UpdatePaymentRequest;
use App\Module\Payment\Exception\PaymentError;
use App\Module\Payment\Exception\PaymentException;
use App\Module\Payment\Service\PaymentService;

final class PaymentController
{
    private PaymentService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(PaymentService::class);
    }

    public function show(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        try {
            $payment = $this->service->getById($id, $request->getNotaryId());
        } catch (PaymentException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::json(PaymentResponse::fromEntity($payment));
    }

    public function showByUuid(Request $request): Response
    {
        $uuid = $request->getQueryParam('uuid');
        if ($uuid === null) {
            return Response::badRequest('uuid query parameter is required');
        }

        // ?uuid[]=x arrives as an array: uuid() rejects it because it is not a string
        if (PaymentFieldRules::uuid($uuid) !== null) {
            return Response::badRequest('Invalid uuid format');
        }

        try {
            $payment = $this->service->getByUuid($uuid, $request->getNotaryId());
        } catch (PaymentException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::json(PaymentResponse::fromEntity($payment));
    }

    public function store(Request $request): Response
    {
        $dto = CreatePaymentRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        try {
            $result = $this->service->create($dto, $request->getNotaryId());
        } catch (PaymentException $e) {
            return $this->errorResponse($e->error);
        }

        $body = PaymentResponse::fromEntity($result->payment);

        return $result->created ? Response::created($body) : Response::json($body);
    }

    public function update(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        $dto = UpdatePaymentRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        try {
            $payment = $this->service->update($id, $dto, $request->getNotaryId());
        } catch (PaymentException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::json(PaymentResponse::fromEntity($payment));
    }

    private function parseId(Request $request): int|Response
    {
        $id = filter_var($request->getRouteParam('id'), FILTER_VALIDATE_INT);

        if ($id === false) {
            return Response::badRequest('Invalid ID format');
        }

        if ($id <= 0) {
            return Response::notFound(PaymentError::PaymentNotFound->message());
        }

        return $id;
    }

    private function errorResponse(PaymentError $error): Response
    {
        return match ($error->httpStatus()) {
            400 => Response::badRequest($error->message()),
            404 => Response::notFound($error->message()),
            default => Response::unprocessable($error->message()),
        };
    }
}
