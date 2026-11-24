<?php

declare(strict_types=1);

namespace App\Module\DeedType\DTO;

use App\Module\DeedType\Entity\DeedType;

final class DeedTypeResponse
{
    public static function fromEntity(DeedType $deedType): array
    {
        return [
            'deedTypeId' => $deedType->id,
            'notaryId' => $deedType->notaryId,
            'name' => $deedType->name,
            'description' => $deedType->description,
            'maxInscriptions' => $deedType->maxInscriptions,
            'enableSubdivisions' => (bool) $deedType->enableSubdivisions,
            'userId' => $deedType->userId,
            'modificationDate' => $deedType->modificationDate->format('Y-m-d\TH:i:s'),
            'status' => $deedType->statusId,
        ];
    }

    public static function fromCollection(array $deedTypes): array
    {
        return array_map(
            fn(DeedType $dt) => self::fromEntity($dt),
            $deedTypes
        );
    }
}
