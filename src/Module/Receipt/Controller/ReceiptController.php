<?php

declare(strict_types=1);

namespace App\Module\Receipt\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Payment\Exception\PaymentError;
use App\Module\Payment\Exception\PaymentException;
use App\Module\PaymentTransaction\Exception\TransactionError;
use App\Module\PaymentTransaction\Exception\TransactionException;
use App\Module\Receipt\DTO\ReceiptData;
use App\Module\Receipt\Renderer\ReceiptPdfRenderer;
use App\Module\Receipt\Service\ReceiptService;
use App\Shared\Uuid\ClientUuid;

final class ReceiptController
{
    private ReceiptService $service;
    private ReceiptPdfRenderer $renderer;

    public function __construct()
    {
        $this->service = ServiceLocator::get(ReceiptService::class);
        $this->renderer = ServiceLocator::get(ReceiptPdfRenderer::class);
    }

    public function transaction(Request $request): Response
    {
        $id = $this->parseId($request, TransactionError::TransactionNotFound->message());
        if ($id instanceof Response) {
            return $id;
        }

        $notaryId = $request->getNotaryId();

        return $this->respond(fn (): ReceiptData => $this->service->buildForTransactionId($id, $notaryId));
    }

    public function transactionByUuid(Request $request): Response
    {
        $uuid = $this->parseUuid($request);
        if ($uuid instanceof Response) {
            return $uuid;
        }

        $notaryId = $request->getNotaryId();

        return $this->respond(fn (): ReceiptData => $this->service->buildForTransactionUuid($uuid, $notaryId));
    }

    public function payment(Request $request): Response
    {
        $id = $this->parseId($request, PaymentError::PaymentNotFound->message());
        if ($id instanceof Response) {
            return $id;
        }

        $notaryId = $request->getNotaryId();

        return $this->respond(fn (): ReceiptData => $this->service->buildForPaymentId($id, $notaryId));
    }

    public function paymentByUuid(Request $request): Response
    {
        $uuid = $this->parseUuid($request);
        if ($uuid instanceof Response) {
            return $uuid;
        }

        $notaryId = $request->getNotaryId();

        return $this->respond(fn (): ReceiptData => $this->service->buildForPaymentUuid($uuid, $notaryId));
    }

    /**
     * The PDF is built completely before any header is sent: any failure is a JSON error, never a partial PDF.
     *
     * @param \Closure(): ReceiptData $build
     */
    private function respond(\Closure $build): Response
    {
        try {
            $data = $build();

            return Response::pdf($this->renderer->render($data), $data->fileName());
        } catch (TransactionException | PaymentException $e) {
            return Response::notFound($e->error->message());
        } catch (\Throwable $e) {
            error_log('[receipt] ' . $e::class . ': ' . $e->getMessage());

            return Response::serverError('Could not generate receipt');
        }
    }

    /** Same contract as the existing GETs: not an integer is 400, zero or negative is 404. */
    private function parseId(Request $request, string $notFoundMessage): int|Response
    {
        $id = filter_var($request->getRouteParam('id'), FILTER_VALIDATE_INT);

        if ($id === false) {
            return Response::badRequest('Invalid ID format');
        }

        if ($id <= 0) {
            return Response::notFound($notFoundMessage);
        }

        return $id;
    }

    private function parseUuid(Request $request): string|Response
    {
        $uuid = $request->getRouteParam('uuid');

        if (ClientUuid::validate($uuid) !== null) {
            return Response::badRequest('Invalid uuid format');
        }

        return $uuid;
    }
}
