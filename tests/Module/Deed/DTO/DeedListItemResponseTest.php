<?php

declare(strict_types=1);

namespace Tests\Module\Deed\DTO;

use App\Module\Deed\DTO\DeedListItemResponse;
use App\Module\Deed\Entity\DeedListItem;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DeedListItemResponseTest extends TestCase
{
    public function testFromEntityProducesExpectedShape(): void
    {
        $deed = new DeedListItem(
            id: 1,
            deedNumber: '39734',
            book: '750',
            deedDate: new DateTimeImmutable('2026-10-30 13:45:00'),
            actName: 'Compraventa',
            deedTypeName: 'Actuales',
            isFinished: false,
        );

        $this->assertSame([
            'id' => 1,
            'deedNumber' => '39734',
            'book' => '750',
            'deedDate' => '2026-10-30',
            'actName' => 'Compraventa',
            'deedTypeName' => 'Actuales',
            'isFinished' => false,
        ], DeedListItemResponse::fromEntity($deed));
    }

    public function testFromEntityKeepsNullsExplicit(): void
    {
        $deed = new DeedListItem(2, 'CDXXIX', '', null, null, null, true);

        $response = DeedListItemResponse::fromEntity($deed);

        $this->assertNull($response['deedDate']);
        $this->assertNull($response['actName']);
        $this->assertNull($response['deedTypeName']);
        $this->assertTrue($response['isFinished']);
    }

    public function testFromCollectionMapsEveryItem(): void
    {
        $deeds = [
            new DeedListItem(1, '1', '', null, null, null, false),
            new DeedListItem(2, '2', '', null, null, null, false),
        ];

        $this->assertCount(2, DeedListItemResponse::fromCollection($deeds));
    }
}
