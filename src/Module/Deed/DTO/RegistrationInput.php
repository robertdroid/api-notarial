<?php

declare(strict_types=1);

namespace App\Module\Deed\DTO;

/**
 * Validated registration data, ready to be persisted. At least one field is not null.
 */
final class RegistrationInput
{
    public function __construct(
        public readonly ?string $number,
        public readonly ?string $section,
        public readonly ?string $volume,
        public readonly ?string $date,
    ) {
    }
}
