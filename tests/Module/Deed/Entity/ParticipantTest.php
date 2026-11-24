<?php

declare(strict_types=1);

namespace Tests\Module\Deed\Entity;

use App\Module\Deed\Entity\Participant;
use App\Module\Deed\Entity\ParticipantType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParticipantTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id_participante' => '15',
            'id_escritura' => '7',
            'nombre_participante' => 'MARIA GARCIA',
            'id_tipo_participante' => '1',
            'id_estatus' => '1',
        ], $overrides);
    }

    public function testFromRowMapsFields(): void
    {
        $participant = Participant::fromRow($this->row());

        $this->assertSame(15, $participant->id);
        $this->assertSame(7, $participant->deedId);
        $this->assertSame('MARIA GARCIA', $participant->name);
        $this->assertSame(1, $participant->typeId);
        $this->assertSame(ParticipantType::Seller, $participant->type());
    }

    public function testNullNameBecomesEmptyString(): void
    {
        $this->assertSame('', Participant::fromRow($this->row(['nombre_participante' => null]))->name);
    }

    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function statusProvider(): array
    {
        return [
            'active' => ['1', true],
            'legacy deleted (0)' => ['0', false],
            'inactive (2)' => ['2', false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testOnlyStatusOneIsActive(mixed $status, bool $expected): void
    {
        $this->assertSame($expected, Participant::fromRow($this->row(['id_estatus' => $status]))->isActive());
    }

    public function testUnknownTypeIdHasNoType(): void
    {
        $participant = Participant::fromRow($this->row(['id_tipo_participante' => '9']));

        $this->assertNull($participant->type());
    }

    public function testTypeLabels(): void
    {
        $this->assertSame('Enajenante', ParticipantType::Seller->label());
        $this->assertSame('Adquiriente', ParticipantType::Buyer->label());
        $this->assertNull(ParticipantType::tryFrom(3));
    }
}
