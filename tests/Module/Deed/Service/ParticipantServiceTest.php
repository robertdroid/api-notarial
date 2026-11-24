<?php

declare(strict_types=1);

namespace Tests\Module\Deed\Service;

use App\Core\Database\TransactionManager;
use App\Module\Deed\DTO\ParticipantInput;
use App\Module\Deed\Entity\DeedLock;
use App\Module\Deed\Entity\Participant;
use App\Module\Deed\Entity\ParticipantType;
use App\Module\Deed\Exception\DeedError;
use App\Module\Deed\Exception\DeedException;
use App\Module\Deed\Repository\DeedRepository;
use App\Module\Deed\Repository\ParticipantRepository;
use App\Module\Deed\Service\ParticipantService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ParticipantServiceTest extends TestCase
{
    private const NOTARY = 1;
    private const USER = 7;
    private const DEED = 123;
    private const PARTICIPANT = 15;

    private DeedRepository&MockObject $deeds;
    private ParticipantRepository&MockObject $participants;
    private ParticipantService $service;

    protected function setUp(): void
    {
        $this->deeds = $this->createMock(DeedRepository::class);
        $this->participants = $this->createMock(ParticipantRepository::class);
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->method('transactional')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation());

        $this->service = new ParticipantService($this->deeds, $this->participants, $transactions);
    }

    private function input(): ParticipantInput
    {
        return new ParticipantInput('Carlos', ParticipantType::Seller);
    }

    private function givenDeedLock(): void
    {
        $this->deeds->method('lockForUpdate')->with(self::DEED, self::NOTARY)
            ->willReturn(new DeedLock(self::DEED, 5, 3, null, false));
    }

    private function participant(int $status = 1): Participant
    {
        return new Participant(self::PARTICIPANT, self::DEED, 'Carlos', 1, $status);
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
        $this->deeds->method('findHeaderByIdAndNotary')->with(self::DEED, self::NOTARY)->willReturn(null);
        $this->participants->expects($this->never())->method('findActiveByDeed');

        $this->assertDeedError(DeedError::DeedNotFound, fn () => $this->service->list(self::DEED, self::NOTARY));
    }

    public function testListReturnsActiveParticipants(): void
    {
        $this->deeds->method('findHeaderByIdAndNotary')->willReturn(['id_escritura' => '123']);
        $active = [$this->participant()];
        $this->participants->method('findActiveByDeed')->with(self::DEED)->willReturn($active);

        $this->assertSame($active, $this->service->list(self::DEED, self::NOTARY));
    }

    public function testCreateOnDeedFromAnotherNotaryIsNotFound(): void
    {
        $this->deeds->method('lockForUpdate')->willReturn(null);
        $this->participants->expects($this->never())->method('insert');

        $this->assertDeedError(
            DeedError::DeedNotFound,
            fn () => $this->service->create(self::DEED, $this->input(), self::NOTARY, self::USER)
        );
    }

    public function testCreateInsertsAndAuditsTheDeed(): void
    {
        $this->givenDeedLock();
        $input = $this->input();
        $this->participants->expects($this->once())->method('insert')->with(self::DEED, $input)->willReturn(self::PARTICIPANT);
        $this->participants->method('findByIdAndDeed')->with(self::PARTICIPANT, self::DEED)->willReturn($this->participant());
        $this->deeds->expects($this->once())->method('touch')
            ->with(self::DEED, self::NOTARY, self::USER, $this->isInstanceOf(\DateTimeImmutable::class));

        $created = $this->service->create(self::DEED, $input, self::NOTARY, self::USER);

        $this->assertSame(self::PARTICIPANT, $created->id);
    }

    public function testUpdateOfParticipantFromAnotherDeedIsNotFound(): void
    {
        $this->givenDeedLock();
        $this->participants->method('findByIdAndDeed')->willReturn(null);
        $this->participants->expects($this->never())->method('update');

        $this->assertDeedError(
            DeedError::ParticipantNotFound,
            fn () => $this->service->update(self::DEED, self::PARTICIPANT, $this->input(), self::NOTARY, self::USER)
        );
    }

    /** @return array<string, array{0: int}> */
    public static function inactiveStatusProvider(): array
    {
        return ['inactive (2)' => [2], 'legacy deleted (0)' => [0]];
    }

    #[DataProvider('inactiveStatusProvider')]
    public function testUpdateOfInactiveParticipantConflicts(int $status): void
    {
        $this->givenDeedLock();
        $this->participants->method('findByIdAndDeed')->willReturn($this->participant($status));
        $this->participants->expects($this->never())->method('update');
        $this->deeds->expects($this->never())->method('touch');

        $this->assertDeedError(
            DeedError::ParticipantInactive,
            fn () => $this->service->update(self::DEED, self::PARTICIPANT, $this->input(), self::NOTARY, self::USER)
        );
    }

    public function testUpdateWritesAndAudits(): void
    {
        $this->givenDeedLock();
        $input = $this->input();
        $this->participants->method('findByIdAndDeed')->willReturn($this->participant());
        $this->participants->expects($this->once())->method('update')->with(self::PARTICIPANT, self::DEED, $input);
        $this->deeds->expects($this->once())->method('touch');

        $this->service->update(self::DEED, self::PARTICIPANT, $input, self::NOTARY, self::USER);
    }

    public function testDeactivateOfParticipantFromAnotherDeedIsNotFound(): void
    {
        $this->givenDeedLock();
        $this->participants->method('findByIdAndDeed')->willReturn(null);

        $this->assertDeedError(
            DeedError::ParticipantNotFound,
            fn () => $this->service->deactivate(self::DEED, self::PARTICIPANT, self::NOTARY, self::USER)
        );
    }

    #[DataProvider('inactiveStatusProvider')]
    public function testDeactivateOfInactiveParticipantConflicts(int $status): void
    {
        $this->givenDeedLock();
        $this->participants->method('findByIdAndDeed')->willReturn($this->participant($status));
        $this->participants->expects($this->never())->method('deactivate');

        $this->assertDeedError(
            DeedError::ParticipantAlreadyInactive,
            fn () => $this->service->deactivate(self::DEED, self::PARTICIPANT, self::NOTARY, self::USER)
        );
    }

    public function testDeactivateWritesAndAudits(): void
    {
        $this->givenDeedLock();
        $this->participants->method('findByIdAndDeed')->willReturn($this->participant());
        $this->participants->expects($this->once())->method('deactivate')->with(self::PARTICIPANT, self::DEED);
        $this->deeds->expects($this->once())->method('touch')
            ->with(self::DEED, self::NOTARY, self::USER, $this->isInstanceOf(\DateTimeImmutable::class));

        $this->service->deactivate(self::DEED, self::PARTICIPANT, self::NOTARY, self::USER);
    }
}
