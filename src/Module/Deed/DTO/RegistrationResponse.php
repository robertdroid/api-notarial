<?php

declare(strict_types=1);

namespace App\Module\Deed\DTO;

use App\Module\Deed\Entity\Registration;

final class RegistrationResponse
{
    /** @return array<string, mixed> */
    public static function fromEntity(Registration $registration): array
    {
        return [
            'id' => $registration->id,
            'number' => $registration->number,
            'section' => $registration->section,
            'volume' => $registration->volume,
            'date' => $registration->date,
        ];
    }

    /**
     * @param Registration[] $registrations
     * @return array<int, array<string, mixed>>
     */
    public static function fromCollection(array $registrations): array
    {
        return array_map(static fn (Registration $r): array => self::fromEntity($r), $registrations);
    }
}
