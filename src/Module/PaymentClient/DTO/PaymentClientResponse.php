<?php

declare(strict_types=1);

namespace App\Module\PaymentClient\DTO;

use App\Module\PaymentClient\Entity\PaymentClient;

final class PaymentClientResponse
{
    /**
     * @return array{clientId: int, fullName: string, phone: string, email: string, status: int}
     */
    public static function fromEntity(PaymentClient $client): array
    {
        return [
            'clientId' => $client->id,
            'fullName' => $client->fullName,
            'phone' => $client->phone,
            'email' => $client->email,
            'status' => $client->statusId,
        ];
    }

    /**
     * @param PaymentClient[] $clients
     * @return array<int, array{clientId: int, fullName: string, phone: string, email: string, status: int}>
     */
    public static function fromCollection(array $clients): array
    {
        return array_map(fn (PaymentClient $client) => self::fromEntity($client), $clients);
    }
}
