<?php

declare(strict_types=1);

namespace Tests\Module\Deed\Service;

use App\Core\Database\TransactionManager;
use App\Module\Act\Entity\Act;
use App\Module\Act\Repository\ActRepository;
use App\Module\Deed\DTO\CreateDeedRequest;
use App\Module\Deed\DTO\DeedListQuery;
use App\Module\Deed\DTO\UpdateDeedRequest;
use App\Module\Deed\Entity\DeedListItem;
use App\Module\Deed\Entity\DeedLock;
use App\Module\Deed\Entity\Participant;
use App\Module\Deed\Entity\Registration;
use App\Module\Deed\Exception\DeedError;
use App\Module\Deed\Exception\DeedException;
use App\Module\Deed\Repository\DeedRepository;
use App\Module\Deed\Repository\ParticipantRepository;
use App\Module\Deed\Repository\RegistrationRepository;
use App\Module\Deed\Service\DeedReferenceValidator;
use App\Module\Deed\Service\DeedService;
use App\Module\DeedType\Entity\DeedType;
use App\Module\DeedType\Repository\DeedTypeRepository;
use App\Module\Subdivision\Entity\Subdivision;
use App\Module\Subdivision\Repository\SubdivisionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DeedServiceTest extends TestCase
{
    private const NOTARY = 1;
    private const USER = 7;
    private const DEED = 123;

    private DeedRepository&MockObject $repository;
    private ParticipantRepository&MockObject $participants;
    private RegistrationRepository&MockObject $registrations;
    private ActRepository&MockObject $acts;
    private DeedTypeRepository&MockObject $types;
    private SubdivisionRepository&MockObject $subdivisions;
    private TransactionManager&MockObject $transactions;
    private DeedService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(DeedRepository::class);
        $this->participants = $this->createMock(ParticipantRepository::class);
        $this->registrations = $this->createMock(RegistrationRepository::class);
        $this->acts = $this->createMock(ActRepository::class);
        $this->types = $this->createMock(DeedTypeRepository::class);
        $this->subdivisions = $this->createMock(SubdivisionRepository::class);
        $this->transactions = $this->createMock(TransactionManager::class);
        $this->transactions->method('transactional')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation());

        $this->service = new DeedService(
            $this->repository,
            $this->participants,
            $this->registrations,
            new DeedReferenceValidator($this->acts, $this->types, $this->subdivisions),
            $this->transactions,
        );
    }

    // ---- helpers ---------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function body(array $overrides = []): array
    {
        return $overrides + [
            'deedNumber' => '1234', 'book' => '10', 'deedDate' => '2024-01-15', 'actId' => 5, 'deedTypeId' => 3,
        ];
    }

    private function type(int $id = 3, int $max = 2, int $enableSubdivisions = 0, int $status = 1): DeedType
    {
        return new DeedType($id, self::NOTARY, 'Type', '', $max, $enableSubdivisions, 1, new \DateTime(), $status);
    }

    private function act(int $status = 1): Act
    {
        return new Act(5, self::NOTARY, 'Act', '', 1, new \DateTime(), $status);
    }

    private function givenCatalogs(?DeedType $type = null, int $actStatus = 1): void
    {
        $this->acts->method('findByIdAndNotary')->willReturn($this->act($actStatus));
        $this->types->method('findByIdAndNotary')->willReturn($type ?? $this->type());
    }

    private function givenLock(?int $actId = 5, ?int $typeId = 3, ?int $subdivisionId = null, bool $finished = false): void
    {
        $this->repository->method('lockForUpdate')
            ->willReturn(new DeedLock(self::DEED, $actId, $typeId, $subdivisionId, $finished));
    }

    private function givenHeader(): void
    {
        $this->repository->method('findHeaderByIdAndNotary')->willReturn([
            'id_escritura' => (string) self::DEED, 'id_notaria' => (string) self::NOTARY, 'num_escritura' => '1234',
        ]);
    }

    private function assertDeedError(DeedError $expected, callable $operation): DeedException
    {
        try {
            $operation();
        } catch (DeedException $e) {
            $this->assertSame($expected, $e->error);

            return $e;
        }

        $this->fail('DeedException expected');
    }

    // ---- list ------------------------------------------------------------

    public function testFirstPageUsesZeroOffset(): void
    {
        $item = new DeedListItem(1, '10', '', null, null, null, false);

        $this->repository->method('countByNotary')->with(5, ['perez'])->willReturn(250);
        $this->repository->expects($this->once())
            ->method('findPageByNotary')
            ->with(5, ['perez'], DeedService::PER_PAGE, 0)
            ->willReturn([$item]);

        $result = $this->service->list(5, DeedListQuery::fromQueryParams('1', 'perez'));

        $this->assertSame([$item], $result->items);
        $this->assertSame(250, $result->total);
        $this->assertSame(1, $result->page);
        $this->assertSame(DeedService::PER_PAGE, $result->perPage);
        $this->assertSame(3, $result->totalPages());
    }

    public function testThirdPageUsesCorrectOffset(): void
    {
        $this->repository->method('countByNotary')->willReturn(250);
        $this->repository->expects($this->once())
            ->method('findPageByNotary')
            ->with(1, [], DeedService::PER_PAGE, 2 * DeedService::PER_PAGE)
            ->willReturn([]);

        $this->service->list(1, DeedListQuery::fromQueryParams('3', null));
    }

    public function testOutOfRangePageSkipsPageQueryAndKeepsRealTotal(): void
    {
        $this->repository->method('countByNotary')->willReturn(250);
        $this->repository->expects($this->never())->method('findPageByNotary');

        $result = $this->service->list(1, DeedListQuery::fromQueryParams('4', null));

        $this->assertSame([], $result->items);
        $this->assertSame(250, $result->total);
    }

    public function testEmptyResultSkipsPageQuery(): void
    {
        $this->repository->method('countByNotary')->willReturn(0);
        $this->repository->expects($this->never())->method('findPageByNotary');

        $result = $this->service->list(1, DeedListQuery::fromQueryParams(null, 'nothing'));

        $this->assertSame(0, $result->totalPages());
    }

    // ---- getById ---------------------------------------------------------

    public function testGetByIdOfMissingDeedThrowsNotFound(): void
    {
        $this->repository->method('findHeaderByIdAndNotary')->with(99, self::NOTARY)->willReturn(null);
        $this->participants->expects($this->never())->method('findActiveByDeed');

        $this->assertDeedError(DeedError::DeedNotFound, fn () => $this->service->getById(99, self::NOTARY));
    }

    public function testGetByIdCombinesHeaderParticipantsAndRegistrations(): void
    {
        $participant = new Participant(1, self::DEED, 'A', 1, 1);
        $registration = new Registration(1, self::DEED, '1', null, null, null);
        $this->givenHeader();
        $this->participants->expects($this->once())->method('findActiveByDeed')->with(self::DEED)->willReturn([$participant]);
        $this->registrations->expects($this->once())->method('findByDeed')->with(self::DEED)->willReturn([$registration]);

        $deed = $this->service->getById(self::DEED, self::NOTARY);

        $this->assertSame(self::DEED, $deed->id);
        $this->assertSame([$participant], $deed->participants);
        $this->assertSame([$registration], $deed->registrations);
    }

    // ---- create ----------------------------------------------------------

    public function testCreateInsertsDeedParticipantsAndRegistrationsInOneTransaction(): void
    {
        $this->givenCatalogs();
        $this->givenHeader();
        $dto = CreateDeedRequest::fromArray($this->body([
            'participants' => [['name' => 'A', 'typeId' => 1], ['name' => 'B', 'typeId' => 2]],
            'registrations' => [['number' => '1']],
        ]));

        $this->transactions = $this->createMock(TransactionManager::class);
        $this->transactions->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $service = new DeedService(
            $this->repository,
            $this->participants,
            $this->registrations,
            new DeedReferenceValidator($this->acts, $this->types, $this->subdivisions),
            $this->transactions,
        );

        $this->repository->expects($this->once())->method('insert')
            ->with(self::NOTARY, $dto->fields, self::USER, $this->isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(self::DEED);
        $this->participants->expects($this->once())->method('insertMany')->with(self::DEED, $dto->participants);
        $this->registrations->expects($this->once())->method('insertMany')->with(self::DEED, $dto->registrations);

        $deed = $service->create($dto, self::NOTARY, self::USER);

        $this->assertSame(self::DEED, $deed->id);
    }

    public function testCreateWithInactiveActNeverOpensATransaction(): void
    {
        $this->givenCatalogs(actStatus: 2);
        $this->transactions = $this->createMock(TransactionManager::class);
        $this->transactions->expects($this->never())->method('transactional');
        $service = new DeedService(
            $this->repository,
            $this->participants,
            $this->registrations,
            new DeedReferenceValidator($this->acts, $this->types, $this->subdivisions),
            $this->transactions,
        );

        $this->assertDeedError(
            DeedError::ActUnavailable,
            fn () => $service->create(CreateDeedRequest::fromArray($this->body()), self::NOTARY, self::USER)
        );
    }

    public function testCreateWithInactiveTypeFails(): void
    {
        $this->givenCatalogs($this->type(status: 2));

        $this->assertDeedError(
            DeedError::DeedTypeUnavailable,
            fn () => $this->service->create(CreateDeedRequest::fromArray($this->body()), self::NOTARY, self::USER)
        );
    }

    public function testCreateWithInactiveSubdivisionFails(): void
    {
        $this->givenCatalogs($this->type(enableSubdivisions: 1));
        $this->subdivisions->method('findByIdAndNotary')
            ->willReturn(new Subdivision(2, self::NOTARY, 'S', 1, new \DateTime(), 2));

        $this->assertDeedError(
            DeedError::SubdivisionUnavailable,
            fn () => $this->service->create(
                CreateDeedRequest::fromArray($this->body(['subdivisionId' => 2])),
                self::NOTARY,
                self::USER
            )
        );
    }

    public function testCreateWithSubdivisionOnTypeThatDisablesThemFails(): void
    {
        $this->givenCatalogs($this->type(enableSubdivisions: 0));
        $this->subdivisions->method('findByIdAndNotary')
            ->willReturn(new Subdivision(2, self::NOTARY, 'S', 1, new \DateTime(), 1));

        $this->assertDeedError(
            DeedError::SubdivisionNotAllowed,
            fn () => $this->service->create(
                CreateDeedRequest::fromArray($this->body(['subdivisionId' => 2])),
                self::NOTARY,
                self::USER
            )
        );
    }

    public function testCreateWithMoreRegistrationsThanTheTypeAllowsFails(): void
    {
        $this->givenCatalogs($this->type(max: 2));
        $this->repository->expects($this->never())->method('insert');
        $dto = CreateDeedRequest::fromArray($this->body([
            'registrations' => [['number' => '1'], ['number' => '2'], ['number' => '3']],
        ]));

        $e = $this->assertDeedError(
            DeedError::RegistrationLimitExceeded,
            fn () => $this->service->create($dto, self::NOTARY, self::USER)
        );

        $this->assertSame(['maxRegistrations' => 2, 'currentRegistrations' => 3], $e->details);
    }

    // ---- update ----------------------------------------------------------

    public function testUpdateOfMissingDeedThrowsNotFound(): void
    {
        $this->repository->method('lockForUpdate')->willReturn(null);
        $this->repository->expects($this->never())->method('update');

        $this->assertDeedError(
            DeedError::DeedNotFound,
            fn () => $this->service->update(self::DEED, UpdateDeedRequest::fromArray($this->body()), self::NOTARY, self::USER)
        );
    }

    public function testUpdateWithoutCatalogChangesDoesNotValidateStatuses(): void
    {
        // Legacy deed: its act and type are now inactive, but the user is not changing them
        $this->givenLock(actId: 5, typeId: 3, subdivisionId: null);
        $this->acts->expects($this->never())->method('findByIdAndNotary');
        $this->types->method('findByIdAndNotary')->willReturn($this->type(status: 2));
        $this->givenHeader();
        $dto = UpdateDeedRequest::fromArray($this->body());

        $this->repository->expects($this->once())->method('update')
            ->with(self::DEED, self::NOTARY, $dto->fields, self::USER, $this->isInstanceOf(\DateTimeImmutable::class));

        $this->service->update(self::DEED, $dto, self::NOTARY, self::USER);
    }

    public function testUpdateChangingToInactiveActFails(): void
    {
        $this->givenLock(actId: 4);
        $this->givenCatalogs(actStatus: 2);
        $this->repository->expects($this->never())->method('update');

        $this->assertDeedError(
            DeedError::ActUnavailable,
            fn () => $this->service->update(self::DEED, UpdateDeedRequest::fromArray($this->body()), self::NOTARY, self::USER)
        );
    }

    public function testUpdateChangingToInactiveTypeFails(): void
    {
        $this->givenLock(typeId: 9);
        $this->givenCatalogs($this->type(status: 2));

        $this->assertDeedError(
            DeedError::DeedTypeUnavailable,
            fn () => $this->service->update(self::DEED, UpdateDeedRequest::fromArray($this->body()), self::NOTARY, self::USER)
        );
    }

    public function testUpdateChangingToTypeWithLowerLimitChecksCurrentRegistrations(): void
    {
        $this->givenLock(typeId: 9);
        $this->givenCatalogs($this->type(max: 1));
        $this->registrations->method('countByDeed')->with(self::DEED)->willReturn(2);
        $this->repository->expects($this->never())->method('update');

        $this->assertDeedError(
            DeedError::RegistrationLimitExceeded,
            fn () => $this->service->update(self::DEED, UpdateDeedRequest::fromArray($this->body()), self::NOTARY, self::USER)
        );
    }

    public function testUpdateChangingOnlySubdivisionOnTypeThatDisablesThemFails(): void
    {
        $this->givenLock(typeId: 3, subdivisionId: null);
        $this->types->method('findByIdAndNotary')->willReturn($this->type(enableSubdivisions: 0));
        $this->subdivisions->method('findByIdAndNotary')
            ->willReturn(new Subdivision(2, self::NOTARY, 'S', 1, new \DateTime(), 1));
        $this->repository->expects($this->never())->method('update');

        $this->assertDeedError(
            DeedError::SubdivisionNotAllowed,
            fn () => $this->service->update(
                self::DEED,
                UpdateDeedRequest::fromArray($this->body(['subdivisionId' => 2])),
                self::NOTARY,
                self::USER
            )
        );
    }

    public function testUpdateRemovingSubdivisionNeedsNoCatalogCheck(): void
    {
        $this->givenLock(typeId: 3, subdivisionId: 2);
        $this->types->method('findByIdAndNotary')->willReturn($this->type(enableSubdivisions: 0));
        $this->subdivisions->expects($this->never())->method('findByIdAndNotary');
        $this->givenHeader();

        $this->repository->expects($this->once())->method('update');

        $this->service->update(self::DEED, UpdateDeedRequest::fromArray($this->body()), self::NOTARY, self::USER);
    }

    // ---- finish ----------------------------------------------------------

    public function testFinishOfMissingDeedThrowsNotFound(): void
    {
        $this->repository->method('lockForUpdate')->willReturn(null);

        $this->assertDeedError(DeedError::DeedNotFound, fn () => $this->service->finish(self::DEED, self::NOTARY, self::USER));
    }

    public function testFinishOfFinishedDeedFailsWithoutWriting(): void
    {
        $this->givenLock(finished: true);
        $this->repository->expects($this->never())->method('markFinished');

        $this->assertDeedError(
            DeedError::DeedAlreadyFinished,
            fn () => $this->service->finish(self::DEED, self::NOTARY, self::USER)
        );
    }

    public function testFinishMarksTheDeedWithTheTokenUser(): void
    {
        $this->givenLock(finished: false);
        $this->givenHeader();
        $this->repository->expects($this->once())->method('markFinished')
            ->with(self::DEED, self::NOTARY, self::USER, $this->isInstanceOf(\DateTimeImmutable::class));

        $deed = $this->service->finish(self::DEED, self::NOTARY, self::USER);

        $this->assertSame(self::DEED, $deed->id);
    }
}
