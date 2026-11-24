<?php

declare(strict_types=1);

namespace App\Module\Notary\Service;

use App\Module\Notary\Repository\NotaryRepository;
use App\Module\Notary\Entity\Notary;
use App\Module\Notary\DTO\UpdateNotaryRequest;
use finfo;

class NotaryService
{
    private const LOGO_MAX_SIZE = 524288; // 512 KB
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png'];
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png'];
    private const ASSETS_PATH = BASE_PATH . '/public/assets';

    public function __construct(
        private readonly NotaryRepository $repository
    ) {}

    public function getByIdActive(int $notaryId): ?Notary
    {
        return $this->repository->findByIdActive($notaryId);
    }

    public function update(int $notaryId, UpdateNotaryRequest $request, ?array $file): bool|string
    {
        $notary = $this->repository->findByIdActive($notaryId);

        if ($notary === null) {
            return 'notary_not_found';
        }

        // Update text fields
        $fields = $request->getFieldsToUpdate();
        $this->repository->update($notaryId, $fields);

        // Process logo if provided
        if ($file !== null && isset($file['tmp_name']) && $file['error'] === UPLOAD_ERR_OK) {
            $logoResult = $this->saveLogo($notaryId, $file, $notary->logo);
            if ($logoResult !== true) {
                return $logoResult;
            }
        }

        return true;
    }

    public function saveLogo(int $notaryId, array $file, string $currentLogo): bool|string
    {
        // 1. Validate size
        if ($file['size'] > self::LOGO_MAX_SIZE) {
            return 'Logo file exceeds maximum size of 512 KB';
        }

        // 2. Validate extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return 'Logo must be JPG or PNG format';
        }

        // Normalize jpeg to jpg
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        // 3. Validate real MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, self::ALLOWED_MIMES, true)) {
            return 'Invalid image file';
        }

        // 4. Validate it's a real image
        $imageInfo = getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return 'Invalid image file';
        }

        // 5. Create directory if it doesn't exist
        $logoDir = self::ASSETS_PATH . "/{$notaryId}/logo";
        if (!is_dir($logoDir)) {
            if (!mkdir($logoDir, 0755, true)) {
                return 'Failed to create logo directory';
            }
        }

        // 6. Delete old logo if it exists with different extension
        $this->deleteOldLogo($notaryId, $extension, $currentLogo);

        // 7. Save new logo
        $filename = "logo_{$notaryId}.{$extension}";
        $fullPath = "{$logoDir}/{$filename}";

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            return 'Failed to save logo file';
        }

        chmod($fullPath, 0644);

        // 8. Update database
        $relativePath = "assets/{$notaryId}/logo/{$filename}";
        $this->repository->update($notaryId, ['logo' => $relativePath]);

        return true;
    }

    private function deleteOldLogo(int $notaryId, string $newExtension, string $currentLogo): void
    {
        if ($currentLogo === '') {
            return;
        }

        // Check if current logo has different extension
        $currentExt = strtolower(pathinfo($currentLogo, PATHINFO_EXTENSION));
        if ($currentExt === 'jpeg') {
            $currentExt = 'jpg';
        }

        if ($currentExt !== $newExtension) {
            $oldPath = self::ASSETS_PATH . "/{$notaryId}/logo/logo_{$notaryId}.{$currentExt}";
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }
    }

    public function getLogoInfo(int $notaryId): ?array
    {
        $notary = $this->repository->findByIdActive($notaryId);

        if ($notary === null || !$notary->hasLogo()) {
            return null;
        }

        $fullPath = BASE_PATH . '/public/' . $notary->logo;

        if (!file_exists($fullPath)) {
            return null;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($fullPath);

        return [
            'path' => $fullPath,
            'mimeType' => $mimeType,
            'size' => filesize($fullPath),
        ];
    }
}
