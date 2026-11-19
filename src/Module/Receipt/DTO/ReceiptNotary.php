<?php

declare(strict_types=1);

namespace App\Module\Receipt\DTO;

final class ReceiptNotary
{
    /** @param string|null $logoDataUri data:image/...;base64,... or null when the notary has no usable logo */
    public function __construct(
        public readonly string $name,
        public readonly string $notaryName,
        public readonly string $rfc,
        public readonly string $address,
        public readonly string $city,
        public readonly string $phone,
        public readonly ?string $logoDataUri,
    ) {
    }
}
