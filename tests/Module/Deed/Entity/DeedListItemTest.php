<?php

declare(strict_types=1);

namespace Tests\Module\Deed\Entity;

use App\Module\Deed\Entity\DeedListItem;
use PHPUnit\Framework\TestCase;

final class DeedListItemTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id_escritura' => '7',
            'num_escritura' => '39734',
            'libro' => '750',
            'fecha_escritura' => '2026-10-30',
            'nombre_acto' => 'Compraventa',
            'nombre_tipo' => 'Actuales',
            'terminada' => '1',
        ], $overrides);
    }

    public function testFromRowMapsFullRow(): void
    {
        $deed = DeedListItem::fromRow($this->row());

        $this->assertSame(7, $deed->id);
        $this->assertSame('39734', $deed->deedNumber);
        $this->assertSame('750', $deed->book);
        $this->assertSame('2026-10-30', $deed->deedDate?->format('Y-m-d'));
        $this->assertSame('Compraventa', $deed->actName);
        $this->assertSame('Actuales', $deed->deedTypeName);
        $this->assertTrue($deed->isFinished);
    }

    public function testNullDateAndNamesAreKeptAsNull(): void
    {
        $deed = DeedListItem::fromRow($this->row([
            'fecha_escritura' => null,
            'nombre_acto' => null,
            'nombre_tipo' => null,
        ]));

        $this->assertNull($deed->deedDate);
        $this->assertNull($deed->actName);
        $this->assertNull($deed->deedTypeName);
    }

    public function testDeedNumberIsTrimmed(): void
    {
        $deed = DeedListItem::fromRow($this->row(['num_escritura' => '18234 ']));

        $this->assertSame('18234', $deed->deedNumber);
    }

    public function testIsFinishedMapping(): void
    {
        $this->assertTrue(DeedListItem::fromRow($this->row(['terminada' => '1']))->isFinished);
        $this->assertFalse(DeedListItem::fromRow($this->row(['terminada' => '0']))->isFinished);
        $this->assertFalse(DeedListItem::fromRow($this->row(['terminada' => null]))->isFinished);
    }
}
