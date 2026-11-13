<?php

declare(strict_types=1);

namespace Tests\Module\Me\DTO;

use App\Module\Me\DTO\MeProfile;
use App\Module\Me\DTO\MeResponse;
use App\Module\Notary\Entity\Notary;
use App\Module\User\Entity\User;
use PHPUnit\Framework\TestCase;

final class MeResponseTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function build(string $maternal = 'García', string $logo = 'logo.png'): array
    {
        $user = new User(
            id: 7,
            notaryId: 1,
            username: 'maria.lopez',
            firstName: 'María',
            paternalLastName: 'López',
            maternalLastName: $maternal,
            access: ['ESC', 'PAG'],
            statusId: 1,
            lastAccess: new \DateTimeImmutable('2026-11-10 08:30:00'),
        );
        $notary = new Notary(1, 'Notaría 1', 'Lic. Pérez', 'CDMX', 'Calle 1', '5555555555', 'XAXX010101000', $logo, 1);

        return MeResponse::fromProfile(new MeProfile($user, $notary));
    }

    public function testShape(): void
    {
        $data = self::build();

        $this->assertSame(['user', 'notary'], array_keys($data));
        $this->assertSame(
            ['id', 'username', 'firstName', 'paternalLastName', 'maternalLastName', 'fullName', 'access', 'status', 'lastAccess'],
            array_keys($data['user']),
        );
        $this->assertSame('María López García', $data['user']['fullName']);
        $this->assertSame('2026-11-10T08:30:00', $data['user']['lastAccess']);
    }

    public function testFullNameWithoutMaternalLastNameHasNoTrailingSpace(): void
    {
        $this->assertSame('María López', self::build(maternal: '')['user']['fullName']);
    }

    public function testDoesNotExposeSensitiveFields(): void
    {
        $data = self::build();

        $this->assertArrayNotHasKey('notaryId', $data['user']);
        $this->assertArrayNotHasKey('logo', $data['notary']);
        $this->assertTrue($data['notary']['hasLogo']);
        $this->assertStringNotContainsString('password', strtolower((string) json_encode($data)));
        $this->assertStringNotContainsString('logo.png', (string) json_encode($data));
    }
}
