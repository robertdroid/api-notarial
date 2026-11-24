<?php

declare(strict_types=1);

namespace App\Module\Deed\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Deed\DTO\RegistrationResponse;
use App\Module\Deed\DTO\SaveRegistrationRequest;
use App\Module\Deed\Exception\DeedError;
use App\Module\Deed\Exception\DeedException;
use App\Module\Deed\Service\RegistrationService;

final class RegistrationController
{
    use ParsesDeedIds;

    private RegistrationService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(RegistrationService::class);
    }

    public function index(Request $request): Response
    {
        $deedId = $this->parseId($request, 'id', DeedError::DeedNotFound);
        if ($deedId instanceof Response) {
            return $deedId;
        }

        try {
            $registrations = $this->service->list($deedId, $request->getNotaryId());
        } catch (DeedException $e) {
            return DeedErrorResponse::fromException($e);
        }

        return Response::json(RegistrationResponse::fromCollection($registrations));
    }

    public function store(Request $request): Response
    {
        $deedId = $this->parseId($request, 'id', DeedError::DeedNotFound);
        if ($deedId instanceof Response) {
            return $deedId;
        }

        $dto = SaveRegistrationRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return DeedErrorResponse::validation($dto->getErrors());
        }

        try {
            $registration = $this->service->create(
                $deedId,
                $dto->toInput(),
                $request->getNotaryId(),
                $request->getUserId(),
            );
        } catch (DeedException $e) {
            return DeedErrorResponse::fromException($e);
        }

        return Response::created(RegistrationResponse::fromEntity($registration));
    }

    public function update(Request $request): Response
    {
        $deedId = $this->parseId($request, 'id', DeedError::DeedNotFound);
        if ($deedId instanceof Response) {
            return $deedId;
        }
        $registrationId = $this->parseId($request, 'registrationId', DeedError::RegistrationNotFound);
        if ($registrationId instanceof Response) {
            return $registrationId;
        }

        $dto = SaveRegistrationRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return DeedErrorResponse::validation($dto->getErrors());
        }

        try {
            $registration = $this->service->update(
                $deedId,
                $registrationId,
                $dto->toInput(),
                $request->getNotaryId(),
                $request->getUserId(),
            );
        } catch (DeedException $e) {
            return DeedErrorResponse::fromException($e);
        }

        return Response::json(RegistrationResponse::fromEntity($registration));
    }

    public function destroy(Request $request): Response
    {
        $deedId = $this->parseId($request, 'id', DeedError::DeedNotFound);
        if ($deedId instanceof Response) {
            return $deedId;
        }
        $registrationId = $this->parseId($request, 'registrationId', DeedError::RegistrationNotFound);
        if ($registrationId instanceof Response) {
            return $registrationId;
        }

        try {
            $this->service->delete($deedId, $registrationId, $request->getNotaryId(), $request->getUserId());
        } catch (DeedException $e) {
            return DeedErrorResponse::fromException($e);
        }

        return Response::noContent();
    }
}
