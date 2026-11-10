<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedureTax\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\PaymentProcedureTax\DTO\AddProcedureTaxesRequest;
use App\Module\PaymentProcedureTax\DTO\ProcedureTaxResponse;
use App\Module\PaymentProcedureTax\DTO\UpdateProcedureTaxRequest;
use App\Module\PaymentProcedureTax\Exception\InvalidProcedureTaxItemsException;
use App\Module\PaymentProcedureTax\Exception\ProcedureTaxError;
use App\Module\PaymentProcedureTax\Exception\ProcedureTaxException;
use App\Module\PaymentProcedureTax\Service\PaymentProcedureTaxService;

final class PaymentProcedureTaxController
{
    private PaymentProcedureTaxService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(PaymentProcedureTaxService::class);
    }

    public function index(Request $request): Response
    {
        $procedureId = $this->parseProcedureId($request);
        if ($procedureId instanceof Response) {
            return $procedureId;
        }

        try {
            $taxes = $this->service->getByProcedure($procedureId, $request->getNotaryId());
        } catch (ProcedureTaxException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::json(ProcedureTaxResponse::fromCollection($taxes));
    }

    public function store(Request $request): Response
    {
        $procedureId = $this->parseProcedureId($request);
        if ($procedureId instanceof Response) {
            return $procedureId;
        }

        $dto = AddProcedureTaxesRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        try {
            $this->service->add($procedureId, $dto->items, $request->getNotaryId());
        } catch (ProcedureTaxException $e) {
            return $this->errorResponse($e->error);
        } catch (InvalidProcedureTaxItemsException $e) {
            if (!$dto->isBulk) {
                return $this->errorResponse(array_values($e->itemErrors)[0]);
            }

            $errors = [];
            foreach ($e->itemErrors as $index => $error) {
                $errors["taxes.$index.taxId"] = $error->message();
            }

            return Response::badRequest('Validation failed', $errors);
        }

        return Response::created();
    }

    public function update(Request $request): Response
    {
        $procedureId = $this->parseProcedureId($request);
        if ($procedureId instanceof Response) {
            return $procedureId;
        }

        $taxId = $this->parseTaxId($request);
        if ($taxId instanceof Response) {
            return $taxId;
        }

        $dto = UpdateProcedureTaxRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        try {
            $this->service->updateDefaultValue($procedureId, $taxId, $dto->defaultValue, $request->getNotaryId());
        } catch (ProcedureTaxException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::noContent();
    }

    public function destroy(Request $request): Response
    {
        $procedureId = $this->parseProcedureId($request);
        if ($procedureId instanceof Response) {
            return $procedureId;
        }

        $taxId = $this->parseTaxId($request);
        if ($taxId instanceof Response) {
            return $taxId;
        }

        try {
            $this->service->remove($procedureId, $taxId, $request->getNotaryId());
        } catch (ProcedureTaxException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::noContent();
    }

    private function parseProcedureId(Request $request): int|Response
    {
        $id = filter_var($request->getRouteParam('id'), FILTER_VALIDATE_INT);

        if ($id === false) {
            return Response::badRequest('Invalid ID format');
        }

        if ($id <= 0) {
            return Response::notFound(ProcedureTaxError::ProcedureNotFound->message());
        }

        return $id;
    }

    private function parseTaxId(Request $request): int|Response
    {
        $id = filter_var($request->getRouteParam('taxId'), FILTER_VALIDATE_INT);

        if ($id === false) {
            return Response::badRequest('Invalid tax ID format');
        }

        if ($id <= 0) {
            return Response::notFound(ProcedureTaxError::NotAssigned->message());
        }

        return $id;
    }

    private function errorResponse(ProcedureTaxError $error): Response
    {
        return match ($error->httpStatus()) {
            404 => Response::notFound($error->message()),
            409 => Response::conflict($error->message()),
            default => Response::unprocessable($error->message()),
        };
    }
}
