<?php

declare(strict_types=1);

namespace Tests\Module\PaymentClient\DTO;

use App\Module\PaymentClient\DTO\PaymentClientResponse;
use App\Module\PaymentClient\Entity\PaymentClient;
use PHPUnit\Framework\TestCase;

final class PaymentClientResponseTest extends TestCase
{
    public function testFromEntityExposesOnlyExpectedKeys(): void
    {
        $response = PaymentClientResponse::fromEntity(new PaymentClient(5, 9, 'Juan', '555', 'j@x.com', 2));

        $this->assertSame(
            ['clientId' => 5, 'fullName' => 'Juan', 'phone' => '555', 'email' => 'j@x.com', 'status' => 2],
            $response
        );
        $this->assertArrayNotHasKey('notaryId', $response);
    }

    public function testFromCollectionMapsEveryEntity(): void
    {
        $response = PaymentClientResponse::fromCollection([
            new PaymentClient(1, 1, 'A', '', '', 1),
            new PaymentClient(2, 1, 'B', '', '', 2),
        ]);

        $this->assertCount(2, $response);
        $this->assertSame(2, $response[1]['clientId']);
    }

    public function testFromCollectionReturnsEmptyArray(): void
    {
        $this->assertSame([], PaymentClientResponse::fromCollection([]));
    }
}
