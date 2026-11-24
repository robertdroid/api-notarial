<?php

declare(strict_types=1);

namespace App\Module\Notary\DTO;

use App\Module\Notary\Entity\Notary;

final class NotaryResponse
{
    public static function fromEntity(Notary $notary): array
    {
        return [
            'id' => $notary->id,
            'name' => $notary->name,
            'notaryName' => $notary->notaryName,
            'city' => $notary->city,
            'address' => $notary->address,
            'phone' => $notary->phone,
            'rfc' => $notary->rfc,
            'hasLogo' => $notary->hasLogo(),
            'status' => $notary->status,
        ];
    }
}
