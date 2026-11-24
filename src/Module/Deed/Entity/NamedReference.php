<?php

declare(strict_types=1);

namespace App\Module\Deed\Entity;

/**
 * Reference to a related record: act, deed type, subdivision or user.
 * The name is null when the record cannot be resolved (deleted or from another notary).
 */
final class NamedReference
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $name,
    ) {
    }
}
