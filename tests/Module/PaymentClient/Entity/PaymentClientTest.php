<?php

declare(strict_types=1);

namespace Tests\Module\PaymentClient\Entity;

use App\Module\PaymentClient\Entity\PaymentClient;
use PHPUnit\Framework\TestCase;

final class PaymentClientTest extends TestCase
{
    public function testFromRowMapsAllColumns(): void
    {
        $client = PaymentClient::fromRow([
            'id_client' => '7',
            'id_notary' => '3',
            'full_name' => 'José López',
            'phone' => '555 1234',
            'email' => 'jose@example.com',
            'id_status' => '2',
        ]);

        $this->assertSame(7, $client->id);
        $this->assertSame(3, $client->notaryId);
        $this->assertSame('José López', $client->fullName);
        $this->assertSame('555 1234', $client->phone);
        $this->assertSame('jose@example.com', $client->email);
        $this->assertSame(2, $client->statusId);
    }

    public function testFromRowDefaultsStatusToActiveWhenNull(): void
    {
        $client = PaymentClient::fromRow([
            'id_client' => 1,
            'id_notary' => 1,
            'full_name' => 'Juan',
            'phone' => '',
            'email' => '',
            'id_status' => null,
        ]);

        $this->assertSame(PaymentClient::STATUS_ACTIVE, $client->statusId);
    }

    public function testFromRowDefaultsStatusToActiveWhenMissing(): void
    {
        $client = PaymentClient::fromRow([
            'id_client' => 1,
            'id_notary' => 1,
            'full_name' => 'Juan',
            'phone' => '',
            'email' => '',
        ]);

        $this->assertSame(1, $client->statusId);
    }
}
