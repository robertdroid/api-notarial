<?php

declare(strict_types=1);

namespace Tests\Module\Act\DTO;

use App\Module\Act\DTO\ActResponse;
use App\Module\Act\Entity\Act;
use DateTime;
use PHPUnit\Framework\TestCase;

final class ActResponseTest extends TestCase
{
    public function testFromEntityFormatsCorrectly(): void
    {
        $act = new Act(
            id: 1,
            notaryId: 2,
            name: 'Compraventa',
            description: 'Acto de compraventa',
            userId: 3,
            modificationDate: new DateTime('2026-11-09 12:00:00'),
            statusId: 1,
        );

        $response = ActResponse::fromEntity($act);

        $this->assertEquals([
            'id' => 1,
            'actName' => 'Compraventa',
            'actDescription' => 'Acto de compraventa',
            'status' => 1,
            'modificationDate' => '2026-11-09 12:00:00',
        ], $response);
    }

    public function testFromCollectionFormatsMultipleActs(): void
    {
        $acts = [
            new Act(
                id: 1,
                notaryId: 2,
                name: 'Compraventa',
                description: 'Descripción 1',
                userId: 3,
                modificationDate: new DateTime('2026-11-09 12:00:00'),
                statusId: 1,
            ),
            new Act(
                id: 2,
                notaryId: 2,
                name: 'Donación',
                description: 'Descripción 2',
                userId: 3,
                modificationDate: new DateTime('2026-11-09 13:00:00'),
                statusId: 2,
            ),
        ];

        $response = ActResponse::fromCollection($acts);

        $this->assertCount(2, $response);
        $this->assertEquals('Compraventa', $response[0]['actName']);
        $this->assertEquals('Donación', $response[1]['actName']);
    }

    public function testFromCollectionReturnsEmptyArrayForEmptyInput(): void
    {
        $response = ActResponse::fromCollection([]);

        $this->assertEquals([], $response);
    }
}
