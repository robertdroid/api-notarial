<?php

declare(strict_types=1);

namespace Tests\Module\Act\Entity;

use App\Module\Act\Entity\Act;
use DateTime;
use PHPUnit\Framework\TestCase;

final class ActTest extends TestCase
{
    public function testFromRowCreatesActFromDatabaseRow(): void
    {
        $row = [
            'id_acto' => '1',
            'id_notaria' => '2',
            'nombre_acto' => 'Compraventa',
            'descripcion_acto' => 'Acto de compraventa',
            'id_usuario' => '3',
            'fecha_modificacion' => '2026-11-09 12:00:00',
            'id_status' => '1',
        ];

        $act = Act::fromRow($row);

        $this->assertEquals(1, $act->id);
        $this->assertEquals(2, $act->notaryId);
        $this->assertEquals('Compraventa', $act->name);
        $this->assertEquals('Acto de compraventa', $act->description);
        $this->assertEquals(3, $act->userId);
        $this->assertEquals('2026-11-09 12:00:00', $act->modificationDate->format('Y-m-d H:i:s'));
        $this->assertEquals(1, $act->statusId);
    }

    public function testFromRowHandlesNullDescription(): void
    {
        $row = [
            'id_acto' => '1',
            'id_notaria' => '2',
            'nombre_acto' => 'Compraventa',
            'descripcion_acto' => null,
            'id_usuario' => '3',
            'fecha_modificacion' => '2026-11-09 12:00:00',
            'id_status' => '1',
        ];

        $act = Act::fromRow($row);

        $this->assertEquals('', $act->description);
    }

    public function testToArrayReturnsCorrectFormat(): void
    {
        $date = new DateTime('2026-11-09 12:00:00');
        $act = new Act(
            id: 1,
            notaryId: 2,
            name: 'Compraventa',
            description: 'Acto de compraventa',
            userId: 3,
            modificationDate: $date,
            statusId: 1,
        );

        $array = $act->toArray();

        $this->assertEquals([
            'id' => 1,
            'notaryId' => 2,
            'name' => 'Compraventa',
            'description' => 'Acto de compraventa',
            'userId' => 3,
            'modificationDate' => '2026-11-09 12:00:00',
            'statusId' => 1,
        ], $array);
    }
}
