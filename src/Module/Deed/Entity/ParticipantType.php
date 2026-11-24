<?php

declare(strict_types=1);

namespace App\Module\Deed\Entity;

enum ParticipantType: int
{
    case Seller = 1;
    case Buyer = 2;

    /** Display label (business data, kept in Spanish like act names). Use the id for logic. */
    public function label(): string
    {
        return match ($this) {
            self::Seller => 'Enajenante',
            self::Buyer => 'Adquiriente',
        };
    }
}
