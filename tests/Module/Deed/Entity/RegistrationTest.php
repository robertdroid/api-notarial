<?php

declare(strict_types=1);

namespace Tests\Module\Deed\Entity;

use App\Module\Deed\Entity\Registration;
use PHPUnit\Framework\TestCase;

final class RegistrationTest extends TestCase
{
    public function testFromRowMapsFields(): void
    {
        $registration = Registration::fromRow([
            'id_inscripcion' => '3',
            'id_escritura' => '7',
            'numero_inscripcion' => '12345',
            'seccion_inscripcion' => 'I',
            'tomo_inscripcion' => '100',
            'fecha_inscripcion' => '2024-01-20',
        ]);

        $this->assertSame(3, $registration->id);
        $this->assertSame(7, $registration->deedId);
        $this->assertSame('12345', $registration->number);
        $this->assertSame('I', $registration->section);
        $this->assertSame('100', $registration->volume);
        $this->assertSame('2024-01-20', $registration->date);
    }

    public function testZeroAndNullDatesBecomeNull(): void
    {
        $base = ['id_inscripcion' => '3', 'id_escritura' => '7'];

        $this->assertNull(Registration::fromRow($base + ['fecha_inscripcion' => '0000-00-00'])->date);
        $this->assertNull(Registration::fromRow($base + ['fecha_inscripcion' => null])->date);
    }
}
