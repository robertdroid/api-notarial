<?php

declare(strict_types=1);

namespace App\Module\Act\DTO;

use App\Module\Act\Entity\Act;

final class ActResponse
{
    private function __construct(
        public readonly int $id,
        public readonly string $actName,
        public readonly string $actDescription,
        public readonly int $status,
        public readonly string $modificationDate,
    ) {}

    public static function fromEntity(Act $act): array
    {
        return [
            'id' => $act->id,
            'actName' => $act->name,
            'actDescription' => $act->description,
            'status' => $act->statusId,
            'modificationDate' => $act->modificationDate->format('Y-m-d H:i:s'),
        ];
    }

    public static function fromCollection(array $acts): array
    {
        return array_map(
            fn(Act $act) => self::fromEntity($act),
            $acts
        );
    }
}
