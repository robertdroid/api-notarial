<?php

declare(strict_types=1);

namespace App\Module\Deed\DTO;

use App\Module\Deed\Entity\ParticipantType;

/**
 * Validated participant data, ready to be persisted.
 */
final class ParticipantInput
{
    public function __construct(
        public readonly string $name,
        public readonly ParticipantType $type,
    ) {
    }
}
