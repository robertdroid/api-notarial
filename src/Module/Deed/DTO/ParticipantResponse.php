<?php

declare(strict_types=1);

namespace App\Module\Deed\DTO;

use App\Module\Deed\Entity\Participant;

final class ParticipantResponse
{
    /** @return array<string, mixed> */
    public static function fromEntity(Participant $participant): array
    {
        return [
            'id' => $participant->id,
            'name' => $participant->name,
            'typeId' => $participant->typeId,
            'type' => $participant->type()?->label(),
        ];
    }

    /**
     * @param Participant[] $participants
     * @return array<int, array<string, mixed>>
     */
    public static function fromCollection(array $participants): array
    {
        return array_map(static fn (Participant $p): array => self::fromEntity($p), $participants);
    }
}
