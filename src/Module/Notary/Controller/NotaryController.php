<?php

declare(strict_types=1);

namespace App\Module\Notary\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Notary\Service\NotaryService;
use App\Module\Notary\DTO\UpdateNotaryRequest;
use App\Module\Notary\DTO\NotaryResponse;

class NotaryController
{
    private NotaryService $notaryService;

    public function __construct()
    {
        $this->notaryService = ServiceLocator::get(NotaryService::class);
    }

    public function show(Request $request): Response
    {
        $notaryId = $request->getNotaryId();

        $notary = $this->notaryService->getByIdActive($notaryId);

        if ($notary === null) {
            return Response::notFound('Notary not found');
        }

        return Response::json(NotaryResponse::fromEntity($notary));
    }

    public function update(Request $request): Response
    {
        $notaryId = $request->getNotaryId();

        // For multipart, data comes in $_POST
        $dto = UpdateNotaryRequest::fromArray($_POST);

        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        // Logo file (optional)
        $file = isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE
            ? $_FILES['logo']
            : null;

        $result = $this->notaryService->update($notaryId, $dto, $file);

        if ($result === 'notary_not_found') {
            return Response::notFound('Notary not found');
        }

        if ($result !== true) {
            return Response::badRequest($result);
        }

        return Response::noContent();
    }

    public function logo(Request $request): Response
    {
        $notaryId = $request->getNotaryId();

        $logoInfo = $this->notaryService->getLogoInfo($notaryId);

        if ($logoInfo === null) {
            return Response::notFound('Logo not found');
        }

        return Response::file(
            $logoInfo['path'],
            $logoInfo['mimeType'],
            $logoInfo['size']
        );
    }
}
