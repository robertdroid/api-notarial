<?php

declare(strict_types=1);

namespace App\Module\Deed\DTO;

use App\Module\Deed\Entity\DeedListItem;

final class DeedListItemResponse
{
    /**
     * @return array<string, mixed>
     */
    public static function fromEntity(DeedListItem $deed): array
    {
        return [
            'id' => $deed->id,
            'deedNumber' => $deed->deedNumber,
            'book' => $deed->book,
            'deedDate' => $deed->deedDate?->format('Y-m-d'),
            'actName' => $deed->actName,
            'deedTypeName' => $deed->deedTypeName,
            'isFinished' => $deed->isFinished,
        ];
    }

    /**
     * @param DeedListItem[] $deeds
     * @return array<int, array<string, mixed>>
     */
    public static function fromCollection(array $deeds): array
    {
        return array_map(
            static fn (DeedListItem $deed): array => self::fromEntity($deed),
            $deeds
        );
    }
}
