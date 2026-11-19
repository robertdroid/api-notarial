<?php

declare(strict_types=1);

namespace App\Module\Receipt\Renderer;

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** QR as an inline PNG data URI (needs gd): no temp files and no remote access. */
class QrCodeGenerator
{
    /** The content is the record uuid as is: no URL, nothing to verify publicly. */
    public function dataUri(string $uuid): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'scale' => 5,
            'imageTransparent' => false,
        ]);

        return (new QRCode($options))->render($uuid);
    }
}
