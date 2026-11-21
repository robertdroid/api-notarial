<?php

declare(strict_types=1);

namespace App\Shared\Pdf;

use App\Module\Notary\Entity\Notary;
use App\Module\Notary\Service\NotaryService;

/**
 * The notary logo as a data URI, the only way an image reaches the HTML (never a file path or URL).
 * A missing or unreadable logo is omitted with a log line; it never breaks the document.
 */
final class LogoDataUriReader
{
    private const MIME_TYPES = ['image/png', 'image/jpeg'];

    public function __construct(private readonly NotaryService $notaryService)
    {
    }

    public function read(Notary $notary): ?string
    {
        if (!$notary->hasLogo()) {
            return null;
        }

        $info = $this->notaryService->getLogoInfo($notary->id);
        if ($info === null) {
            error_log(sprintf('[pdf] notary %d has a logo registered but the file does not exist', $notary->id));

            return null;
        }

        $mime = (string) ($info['mimeType'] ?? '');
        $path = (string) ($info['path'] ?? '');
        $bytes = in_array($mime, self::MIME_TYPES, true) ? @file_get_contents($path) : false;

        if ($bytes === false || $bytes === '') {
            error_log(sprintf('[pdf] notary %d logo is unreadable or has an unsupported type (%s)', $notary->id, $mime));

            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
