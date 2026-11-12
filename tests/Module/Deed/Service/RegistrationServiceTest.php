<?php

declare(strict_types=1);

namespace Tests\Module\Deed\Service;

use App\Core\Database\TransactionManager;
use App\Module\Act\Repository\ActRepository;
use App\Module\Deed\DTO\RegistrationInput;
use App\Module\Deed\Entity\DeedLock;
use App\Module\Deed\Entity\Registration;
use App\Module\Deed\Exception\DeedError;
use App\Module\Deed\Exception\DeedException;
use App\Module\Deed\Repository\DeedRepository;
use App\Module\Deed\Repository\RegistrationRepository;
use App\Module\Deed\Service\DeedReferenceValidator;
use App\Module\Deed\Service\RegistrationService;
use App\Module\DeedType\Entity\DeedType;
use App\Module\DeedType\Repository\DeedTypeRepository;
use App\Module\Subdivision\Repository\SubdivisionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RegistrationServiceTest extends TestCase
{
    private const NOTARY = 1;
    private const USER = 7;
    private const DEED = 123;
    private const REGISTRATION = 3;

    private DeedRepository&MockObject $deeds;
    private RegistrationRepository&MockObject $registrations;
    private DeedTypeRepository&MockObject $types;
    private RegistrationService $service;

    protected function setUp(): void
    {
        $this->deeds = $this->createMock(DeedRepository::class);
        $this->registrations = $this->createMock(RegistrationRepository::class);
        $this->types = $this->createMock(DeedTypeRepository::class);
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->method('transactional')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation());

        $this->service = new RegistrationService(
            $this->deeds,
            $this->registrations,
            new DeedReferenceValidator(
                $this->createMock(ActRepository::class),
                $this->types,
                $this->createMock(SubdivisionRepository::class),
            ),
            $transactions,
        );
    }

    private function input(): RegistrationInput
    {
        return new RegistrationInput('54321', 'II', '200', '2024-03-15');
    }

    private function registration(): Registration
    {
        return new Registration(self::REGISTRATION, self::DEED, '54321', 'II', '200', '2024-03-15');
    }

    private function givenDeedLock(?int $typeId = 3): void
    {
        $this->deeds->method('lockForUpdate')->with(self::DEED, self::NOTARY)
            ->willReturn(new DeedLock(self::DEED, 5, $typeId, null, false));
    }

    private function givenTypeWithLimit(int $max, int $status = 1): void
    {
        $this->types->method('findByIdAndNotary')
            ->willReturn(new DeedType(3, self::NOTARY, 'T', '', $max, 0, 1, new \DateTime(), $status));
    }

    private function assertDeedError(DeedError $expected, callable $operation): void
    {
        try {
            $operation();
            $this->fail('DeedException expected');
        } catch (DeedException $e) {
            $this->assertSame($expected, $e->error);
        }
    }

    public function testListOfDeedFromAnotherNotaryIsNotFound(): void
    {
        $this->deeds->method('findHeaderByIdAndNotary')->willReturn(null);

        $this->assertDeedError(DeedError::DeedNotFound, fn () => $this->service->list(self::DEED, self::NOTARY));
    }

    public function testListReturnsTheRegistrations(): void
    {
        $this->deeds->method('findHeaderByIdAndNotary')->willReturn(['id_escritura' => '123']);
        $items = [$this->registration()];
        $this->registrations->method('findByDeed')->with(self::DEED)->willReturn($items);

        $this->assertSame($items, $this->service->list(self::DEED, self::NOTARY));
    }

    public function testCreateOnDeedFromAnotherNotaryIsNotFound(): void
    {
        $this->deeds->method('lockForUpdate')->willReturn(null);
        $this->registrations->expects($this->never())->method('insert');

        $this->assertDeedError(
            DeedError::DeedNotFound,
            fn () => $this->service->create(self::DEED, $this->input(), self::NOTARY, self::USER)
        );
    }

    public function testCreateAtTheLimitFails(): void
    {
        $this->givenDeedLock();
        $this->givenTypeWithLimit(2);
        $this->registrations->method('countByDeed')->willReturn(2);
        $this->registrations->expects($this->never())->method('insert');

        $this->assertDeedError(
            DeedError::RegistrationLimitExceeded,
            fn () => $this->service->create(self::DEED, $this->input(), self::NOTARY, self::USER)
        );
    }

    public function testCreateBelowTheLimitInsertsAndAudits(): void
    {
        $this->givenDeedLock();
        $this->givenTypeWithLimit(2);
        $input = $this->input();
        $this->registrations->method('countByDeed')->willReturn(1);
        $this->registrations->expects($this->once())->method('insert')->with(self::DEED, $input)->willReturn(self::REGISTRATION);
        $this->registrations->method('findByIdAndDeed')->willReturn($this->registration());
        $this->deeds->expects($this->once())->method('touch')
            ->with(self::DEED, self::NOTARY, self::USER, $this->isInstanceOf(\DateTimeImmutable::class));

        $created = $this->service->create(self::DEED, $input, self::NOTARY, self::USER);

        $this->assertSame(self::REGISTRATION, $created->id);
    }

    public function testCreateAppliesTheLimitOfAnInactiveType(): void
    {
        $this->givenDeedLock();
        $this->givenTypeWithLimit(1, status: 2);
        $this->registrations->method('countByDeed')->willReturn(1);

        $this->assertDeedError(
            DeedError::RegistrationLimitExceeded,
            fn () => $this->service->create(self::DEED, $this->input(), self::NOTARY, self::USER)
        );
    }

    public function testCreateWithMissingTypeHasNoLimit(): void
    {
        $this->givenDeedLock();
        $this->types->method('findByIdAndNotary')->willReturn(null);
        $this->registrations->method('countByDeed')->willReturn(50);
        $this->registrations->expects($this->once())->method('insert')->willReturn(self::REGISTRATION);
        $this->registrations->method('findByIdAndDeed')->willReturn($this->registration());

        $this->service->create(self::DEED, $this->input(), self::NOTARY, self::USER);
    }

    public function testCreateOnDeedWithoutTypeHasNoLimit(): void
    {
        $this->givenDeedLock(typeId: null);
        $this->types->expects($this->never())->method('findByIdAndNotary');
        $this->registrations->method('countByDeed')->willReturn(50);
        $this->registrations->expects($this->once())->method('insert')->willReturn(self::REGISTRATION);
        $this->registrations->method('findByIdAndDeed')->willReturn($this->registration());

        $this->service->create(self::DEED, $this->input(), self::NOTARY, self::USER);
    }

    public function testUpdateOfRegistrationFromAnotherDeedIsNotFound(): void
    {
        $this->givenDeedLock();
        $this->registrations->method('findByIdAndDeed')->willReturn(null);
        $this->registrations->expects($this->never())->method('update');

        $this->assertDeedError(
            DeedError::RegistrationNotFound,
            fn () => $this->service->update(self::DEED, self::REGISTRATION, $this->input(), self::NOTARY, self::USER)
        );
    }

    public function testUpdateHasNoLimitCheckAndAudits(): void
    {
        $this->givenDeedLock();
        $this->types->expects($this->never())->method('findByIdAndNotary');
        $input = $this->input();
        $this->registrations->method('findByIdAndDeed')->willReturn($this->registration());
        $this->registrations->expects($this->once())->method('update')->with(self::REGISTRATION, self::DEED, $input);
        $this->deeds->expects($this->once())->method('touch');

        $this->service->update(self::DEED, self::REGISTRATION, $input, self::NOTARY, self::USER);
    }

    public function testDeleteOfRegistrationFromAnotherDeedIsNotFound(): void
    {
        $this->givenDeedLock();
        $this->registrations->method('findByIdAndDeed')->willReturn(null);
        $this->registrations->expects($this->never())->method('delete');

        $this->assertDeedError(
            DeedError::RegistrationNotFound,
            fn () => $this->service->delete(self::DEED, self::REGISTRATION, self::NOTARY, self::USER)
        );
    }

    public function testDeleteRemovesAndAudits(): void
    {
        $this->givenDeedLock();
        $this->registrations->method('findByIdAndDeed')->willReturn($this->registration());
        $this->registrations->expects($this->once())->method('delete')->with(self::REGISTRATION, self::DEED);
        $this->deeds->expects($this->once())->method('touch')
            ->with(self::DEED, self::NOTARY, self::USER, $this->isInstanceOf(\DateTimeImmutable::class));

        $this->service->delete(self::DEED, self::REGISTRATION, self::NOTARY, self::USER);
    }
}
