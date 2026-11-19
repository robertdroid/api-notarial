<?php

declare(strict_types=1);

namespace App\Module\Receipt\DTO;

final class ReceiptClient
{
    public function __construct(
        public readonly string $name,
        public readonly string $phone,
        public readonly string $email,
    ) {
    }
}
