<?php

declare(strict_types=1);

namespace App\Module\Deed\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Deed\DTO\ParticipantResponse;
use App\Module\Deed\DTO\SaveParticipantRequest;
use App\Module\Deed\Exception\DeedError;
use App\Module\Deed\Exception\DeedException;
use App\Module\Deed\Service\ParticipantService;

final class ParticipantController
{
    use ParsesDeedIds;

    private ParticipantService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(ParticipantService::class);
    }

    public function index(Request $request): Response
    {
        $deedId = $this->parseId($request, 'id', DeedError::DeedNotFound);
        if ($deedId instanceof Response) {
            return $deedId;
        }

        try {
            $participants = $this->service->list($deedId, $request->getNotaryId());
        } catch (DeedException $e) {
            return DeedErrorResponse::fromException($e);
        }

        return Response::json(ParticipantResponse::fromCollection($participants));
    }

    public function store(Request $request): Response
    {
        $deedId = $this->parseId($request, 'id', DeedError::DeedNotFound);
        if ($deedId instanceof Response) {
            return $deedId;
        }

        $dto = SaveParticipantRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return DeedErrorResponse::validation($dto->getErrors());
        }

        try {
            $participant = $this->service->create(
                $deedId,
                $dto->toInput(),
                $request->getNotaryId(),
                $request->getUserId(),
            );
        } catch (DeedException $e) {
            return DeedErrorResponse::fromException($e);
        }

        return Response::created(ParticipantResponse::fromEntity($participant));
    }

    public function update(Request $request): Response
    {
        $deedId = $this->parseId($request, 'id', DeedError::DeedNotFound);
        if ($deedId instanceof Response) {
            return $deedId;
        }
        $participantId = $this->parseId($request, 'participantId', DeedError::ParticipantNotFound);
        if ($participantId instanceof Response) {
            return $participantId;
        }

        $dto = SaveParticipantRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return DeedErrorResponse::validation($dto->getErrors());
        }

        try {
            $participant = $this->service->update(
                $deedId,
                $participantId,
                $dto->toInput(),
                $request->getNotaryId(),
                $request->getUserId(),
            );
        } catch (DeedException $e) {
            return DeedErrorResponse::fromException($e);
        }

        return Response::json(ParticipantResponse::fromEntity($participant));
    }

    public function destroy(Request $request): Response
    {
        $deedId = $this->parseId($request, 'id', DeedError::DeedNotFound);
        if ($deedId instanceof Response) {
            return $deedId;
        }
        $participantId = $this->parseId($request, 'participantId', DeedError::ParticipantNotFound);
        if ($participantId instanceof Response) {
            return $participantId;
        }

        try {
            $this->service->deactivate($deedId, $participantId, $request->getNotaryId(), $request->getUserId());
        } catch (DeedException $e) {
            return DeedErrorResponse::fromException($e);
        }

        return Response::noContent();
    }
}
