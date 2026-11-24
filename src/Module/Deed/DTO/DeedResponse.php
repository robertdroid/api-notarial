<?php

declare(strict_types=1);

namespace App\Module\Deed\DTO;

use App\Module\Deed\Entity\Deed;
use App\Module\Deed\Entity\NamedReference;

final class DeedResponse
{
    /** @return array<string, mixed> */
    public static function fromEntity(Deed $deed): array
    {
        return [
            'id' => $deed->id,
            'deedNumber' => $deed->deedNumber,
            'book' => $deed->book,
            'deedDate' => $deed->deedDate,
            'act' => self::reference($deed->act),
            'deedType' => self::reference($deed->deedType),
            'subdivision' => self::reference($deed->subdivision),
            'property' => $deed->property,
            'deliveryDate' => $deed->deliveryDate,
            'deliveredBy' => $deed->deliveredBy,
            'observations' => $deed->observations,
            'isFinished' => $deed->isFinished,
            'participants' => ParticipantResponse::fromCollection($deed->participants),
            'registrations' => RegistrationResponse::fromCollection($deed->registrations),
            'modifiedBy' => self::reference($deed->modifiedBy),
            'modifiedAt' => $deed->modifiedAt?->format(\DATE_ATOM),
        ];
    }

    /** @return array{id: int, name: ?string}|null */
    private static function reference(?NamedReference $reference): ?array
    {
        return $reference === null ? null : ['id' => $reference->id, 'name' => $reference->name];
    }
}
