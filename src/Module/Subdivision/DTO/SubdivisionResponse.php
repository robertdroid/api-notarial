<?php

declare(strict_types=1);

namespace App\Module\Subdivision\DTO;

use App\Module\Subdivision\Entity\Subdivision;

final class SubdivisionResponse
{
    public static function fromEntity(Subdivision $subdivision): array
    {
        return [
            'subdivisionId' => $subdivision->id,
            'notaryId' => $subdivision->notaryId,
            'name' => $subdivision->name,
            'userId' => $subdivision->userId,
            'modificationDate' => $subdivision->modificationDate->format('Y-m-d\TH:i:s'),
            'status' => $subdivision->statusId,
        ];
    }

    public static function fromCollection(array $subdivisions): array
    {
        return array_map(
            fn(Subdivision $s) => self::fromEntity($s),
            $subdivisions
        );
    }
}
