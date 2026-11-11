<?php

declare(strict_types=1);

namespace Tests\Module\User\Entity;

use App\Module\User\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return array_merge([
            'id_usuario' => '7',
            'id_notaria' => '1',
            'usuario' => 'maria.lopez',
            'nombre' => 'María',
            'apellido_paterno' => 'López',
            'apellido_materno' => 'García',
            'user_access' => 'ESC,PAG',
            'id_estatus' => '1',
            'ultimo_acceso' => '2026-11-10 08:30:00',
        ], $overrides);
    }

    public function testFromRowMapsAllColumns(): void
    {
        $user = User::fromRow(self::row());

        $this->assertSame(7, $user->id);
        $this->assertSame(1, $user->notaryId);
        $this->assertSame('maria.lopez', $user->username);
        $this->assertSame('María', $user->firstName);
        $this->assertSame('López', $user->paternalLastName);
        $this->assertSame('García', $user->maternalLastName);
        $this->assertSame(['ESC', 'PAG'], $user->access);
        $this->assertSame(1, $user->statusId);
        $this->assertSame('2026-11-10 08:30:00', $user->lastAccess?->format('Y-m-d H:i:s'));
    }

    public function testFromRowMapsNullMaternalLastNameToEmptyString(): void
    {
        $this->assertSame('', User::fromRow(self::row(['apellido_materno' => null]))->maternalLastName);
    }

    public function testFromRowMapsNullLastAccessToNull(): void
    {
        $this->assertNull(User::fromRow(self::row(['ultimo_acceso' => null]))->lastAccess);
    }

    public function testFromRowKeepsRawStatusAndMapsNullToZero(): void
    {
        $this->assertSame(0, User::fromRow(self::row(['id_estatus' => '0']))->statusId);
        $this->assertSame(0, User::fromRow(self::row(['id_estatus' => null]))->statusId);
    }

    /** @return array<string, array{string|null, int}> */
    public static function statuses(): array
    {
        return [
            'active' => ['1', 1],
            'inactive' => ['2', 2],
            'legacy zero' => ['0', 2],
            'null' => [null, 2],
        ];
    }

    #[DataProvider('statuses')]
    public function testStatusIsNormalized(?string $raw, int $expected): void
    {
        $this->assertSame($expected, User::fromRow(self::row(['id_estatus' => $raw]))->status());
    }

    public function testEntityHasNoPasswordProperties(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $p): string => strtolower($p->getName()),
            (new \ReflectionClass(User::class))->getProperties()
        );

        $this->assertNotEmpty($properties);
        foreach ($properties as $name) {
            $this->assertStringNotContainsString('password', $name);
            $this->assertStringNotContainsString('contrasenia', $name);
        }
    }
}
