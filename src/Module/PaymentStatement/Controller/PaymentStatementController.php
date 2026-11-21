<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\PaymentStatement\DTO\StatementQuery;
use App\Module\PaymentStatement\DTO\StatementResponse;
use App\Module\PaymentStatement\Renderer\StatementPdfRenderer;
use App\Module\PaymentStatement\Service\PaymentStatementService;
use App\Module\PaymentStatement\Service\StatementPdfService;

final class PaymentStatementController
{
    private const NOT_FOUND = 'Client not found';

    private PaymentStatementService $service;
    private StatementPdfService $pdfService;
    private StatementPdfRenderer $pdfRenderer;

    public function __construct()
    {
        $this->service = ServiceLocator::get(PaymentStatementService::class);
        $this->pdfService = ServiceLocator::get(StatementPdfService::class);
        $this->pdfRenderer = ServiceLocator::get(StatementPdfRenderer::class);
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

    public function pdf(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        $query = StatementQuery::fromArray($request->getQueryParams());
        if (!$query->isValid()) {
            return Response::badRequest('Validation failed', $query->getErrors());
        }

        // The PDF is built completely before any header is sent: any failure is a JSON error, never a partial PDF
        try {
            $data = $this->pdfService->build($id, $request->getNotaryId(), $query);
            if ($data === null) {
                return Response::notFound(self::NOT_FOUND);
            }

            return Response::pdf($this->pdfRenderer->render($data), $data->fileName());
        } catch (\Throwable $e) {
            error_log('[statement] ' . $e::class . ': ' . $e->getMessage());

            return Response::serverError('Could not generate statement');
        }
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
