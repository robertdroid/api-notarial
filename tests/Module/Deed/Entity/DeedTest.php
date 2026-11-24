<?php

declare(strict_types=1);

namespace Tests\Module\Deed\Entity;

use App\Module\Deed\Entity\Deed;
use App\Module\Deed\Entity\Participant;
use App\Module\Deed\Entity\Registration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeedTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id_escritura' => '123',
            'id_notaria' => '1',
            'num_escritura' => '1234 ',
            'libro' => '10',
            'fecha_escritura' => '2024-01-15',
            'id_acto' => '5',
            'nombre_acto' => 'Compraventa',
            'id_tipo_escitura' => '3',
            'nombre_tipo' => 'Definitiva',
            'id_fraccionamiento' => '2',
            'nombre_fraccionamiento' => 'Los Pinos',
            'predio' => 'Lote 5',
            'fecha_entrega' => '2024-02-01',
            'entrego' => 'Juan',
            'observaciones' => 'Ninguna',
            'terminada' => '1',
            'id_usuario' => '7',
            'nombre_usuario' => 'Ana Torres',
            'fecha_modificacion' => '2024-01-15 10:30:00',
        ], $overrides);
    }

    public function testFromRowMapsFullRow(): void
    {
        $participants = [new Participant(1, 123, 'A', 1, 1)];
        $registrations = [new Registration(1, 123, '1', null, null, null)];

        $deed = Deed::fromRow($this->row(), $participants, $registrations);

        $this->assertSame(123, $deed->id);
        $this->assertSame(1, $deed->notaryId);
        $this->assertSame('1234', $deed->deedNumber);
        $this->assertSame('10', $deed->book);
        $this->assertSame('2024-01-15', $deed->deedDate);
        $this->assertSame(5, $deed->actId);
        $this->assertSame('Compraventa', $deed->act?->name);
        $this->assertSame(3, $deed->deedTypeId);
        $this->assertSame('Definitiva', $deed->deedType?->name);
        $this->assertSame(2, $deed->subdivisionId);
        $this->assertSame('Los Pinos', $deed->subdivision?->name);
        $this->assertSame('Lote 5', $deed->property);
        $this->assertSame('2024-02-01', $deed->deliveryDate);
        $this->assertSame('Juan', $deed->deliveredBy);
        $this->assertSame('Ninguna', $deed->observations);
        $this->assertTrue($deed->isFinished);
        $this->assertSame(7, $deed->modifiedBy?->id);
        $this->assertSame('Ana Torres', $deed->modifiedBy?->name);
        $this->assertSame($participants, $deed->participants);
        $this->assertSame($registrations, $deed->registrations);
    }

    /** @return array<string, array{0: mixed}> */
    public static function noSubdivisionProvider(): array
    {
        return ['minus one' => ['-1'], 'zero' => ['0'], 'null' => [null]];
    }

    #[DataProvider('noSubdivisionProvider')]
    public function testLegacyNoSubdivisionMarkersBecomeNull(mixed $value): void
    {
        $deed = Deed::fromRow($this->row(['id_fraccionamiento' => $value, 'nombre_fraccionamiento' => null]), [], []);

        $this->assertNull($deed->subdivision);
        $this->assertNull($deed->subdivisionId);
    }

    public function testActWithoutNameKeepsTheId(): void
    {
        $deed = Deed::fromRow($this->row(['nombre_acto' => null]), [], []);

        $this->assertSame(5, $deed->act?->id);
        $this->assertNull($deed->act?->name);
    }

    public function testEmptyUserNameBecomesNull(): void
    {
        $deed = Deed::fromRow($this->row(['nombre_usuario' => '']), [], []);

        $this->assertSame(7, $deed->modifiedBy?->id);
        $this->assertNull($deed->modifiedBy?->name);
    }

    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function finishedProvider(): array
    {
        return ['finished' => ['1', true], 'open' => ['0', false], 'null' => [null, false]];
    }

    #[DataProvider('finishedProvider')]
    public function testIsFinishedOnlyWhenOne(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, Deed::fromRow($this->row(['terminada' => $value]), [], [])->isFinished);
    }

    public function testZeroDatesBecomeNull(): void
    {
        $deed = Deed::fromRow($this->row([
            'fecha_escritura' => '0000-00-00',
            'fecha_entrega' => '0000-00-00',
            'fecha_modificacion' => '0000-00-00 00:00:00',
        ]), [], []);

        $this->assertNull($deed->deedDate);
        $this->assertNull($deed->deliveryDate);
        $this->assertNull($deed->modifiedAt);
    }

    public function testModifiedAtUsesMexicoCityTimezone(): void
    {
        $deed = Deed::fromRow($this->row(), [], []);

        $this->assertSame('America/Mexico_City', $deed->modifiedAt?->getTimezone()->getName());
        $this->assertSame('2024-01-15T10:30:00-06:00', $deed->modifiedAt?->format(\DATE_ATOM));
    }
}
