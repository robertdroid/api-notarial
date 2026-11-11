<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\PaymentTransaction\DTO\CreateTransactionRequest;
use App\Module\PaymentTransaction\DTO\TransactionFieldRules;
use App\Module\PaymentTransaction\DTO\TransactionResponse;
use App\Module\PaymentTransaction\DTO\UpdateTransactionRequest;
use App\Module\PaymentTransaction\Exception\TransactionError;
use App\Module\PaymentTransaction\Exception\TransactionException;
use App\Module\PaymentTransaction\Service\PaymentTransactionService;

final class PaymentTransactionController
{
    private PaymentTransactionService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(PaymentTransactionService::class);
    }

    public function show(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        try {
            $transaction = $this->service->getById($id, $request->getNotaryId());
        } catch (TransactionException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::json(TransactionResponse::fromEntity($transaction));
    }

    public function showByUuid(Request $request): Response
    {
        $uuid = $request->getQueryParam('uuid');
        if ($uuid === null) {
            return Response::badRequest('uuid query parameter is required');
        }

        // ?uuid[]=x arrives as an array: uuid() rejects it because it is not a string
        if (TransactionFieldRules::uuid($uuid) !== null) {
            return Response::badRequest('Invalid uuid format');
        }

        try {
            $transaction = $this->service->getByUuid($uuid, $request->getNotaryId());
        } catch (TransactionException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::json(TransactionResponse::fromEntity($transaction));
    }

    public function store(Request $request): Response
    {
        $dto = CreateTransactionRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        try {
            $result = $this->service->create($dto, $request->getNotaryId());
        } catch (TransactionException $e) {
            return $this->errorResponse($e->error);
        }

        $body = TransactionResponse::fromEntity($result->transaction);

        return $result->created ? Response::created($body) : Response::json($body);
    }

    public function update(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        $dto = UpdateTransactionRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        try {
            $transaction = $this->service->update($id, $dto, $request->getNotaryId());
        } catch (TransactionException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::json(TransactionResponse::fromEntity($transaction));
    }

    private function parseId(Request $request): int|Response
    {
        $id = filter_var($request->getRouteParam('id'), FILTER_VALIDATE_INT);

        if ($id === false) {
            return Response::badRequest('Invalid ID format');
        }

        if ($id <= 0) {
            return Response::notFound(TransactionError::TransactionNotFound->message());
        }

        return $id;
    }

    private function errorResponse(TransactionError $error): Response
    {
        return match ($error->httpStatus()) {
            400 => Response::badRequest($error->message()),
            404 => Response::notFound($error->message()),
            default => Response::unprocessable($error->message()),
        };
    }
}
