<?php

declare(strict_types=1);

namespace Tests\Module\Act\Service;

use App\Module\Act\DTO\CreateActRequest;
use App\Module\Act\DTO\UpdateActRequest;
use App\Module\Act\Entity\Act;
use App\Module\Act\Repository\ActRepository;
use App\Module\Act\Service\ActService;
use DateTime;
use PHPUnit\Framework\TestCase;

final class ActServiceTest extends TestCase
{
    private ActService $actService;
    private ActRepository $actRepository;

    protected function setUp(): void
    {
        $this->actRepository = $this->createMock(ActRepository::class);
        $this->actService = new ActService($this->actRepository);
    }

    public function testGetAllReturnsActsForNotary(): void
    {
        $acts = [
            $this->createAct(1, 'Compraventa'),
            $this->createAct(2, 'Donación'),
        ];

        $this->actRepository
            ->method('findAllByNotary')
            ->with(1, null, null)
            ->willReturn($acts);

        $result = $this->actService->getAll(1, null, null);

        $this->assertCount(2, $result);
        $this->assertEquals('Compraventa', $result[0]->name);
        $this->assertEquals('Donación', $result[1]->name);
    }

    public function testGetAllFiltersByStatus(): void
    {
        $acts = [$this->createAct(1, 'Compraventa', 1)];

        $this->actRepository
            ->method('findAllByNotary')
            ->with(1, 1, null)
            ->willReturn($acts);

        $result = $this->actService->getAll(1, 1, null);

        $this->assertCount(1, $result);
    }

    public function testGetAllFiltersByName(): void
    {
        $acts = [$this->createAct(1, 'Compraventa')];

        $this->actRepository
            ->method('findAllByNotary')
            ->with(1, null, 'compra')
            ->willReturn($acts);

        $result = $this->actService->getAll(1, null, 'compra');

        $this->assertCount(1, $result);
    }

    public function testGetByIdReturnsActWhenExists(): void
    {
        $act = $this->createAct(1, 'Compraventa');

        $this->actRepository
            ->method('findByIdAndNotary')
            ->with(1, 1)
            ->willReturn($act);

        $result = $this->actService->getById(1, 1);

        $this->assertNotNull($result);
        $this->assertEquals('Compraventa', $result->name);
    }

    public function testGetByIdReturnsNullWhenNotExists(): void
    {
        $this->actRepository
            ->method('findByIdAndNotary')
            ->with(999, 1)
            ->willReturn(null);

        $result = $this->actService->getById(999, 1);

        $this->assertNull($result);
    }

    public function testGetByIdReturnsNullWhenDifferentNotary(): void
    {
        $this->actRepository
            ->method('findByIdAndNotary')
            ->with(1, 2)
            ->willReturn(null);

        $result = $this->actService->getById(1, 2);

        $this->assertNull($result);
    }

    public function testCreateSucceedsWithValidData(): void
    {
        $request = CreateActRequest::fromArray([
            'actName' => 'Compraventa',
            'actDescription' => 'Acto de compraventa',
            'status' => 1,
        ]);

        $this->actRepository
            ->method('existsByNameAndNotary')
            ->with('Compraventa', 1)
            ->willReturn(false);

        $this->actRepository
            ->expects($this->once())
            ->method('create')
            ->willReturn(1);

        $result = $this->actService->create($request, 1, 1);

        $this->assertTrue($result);
    }

    public function testCreateFailsWhenNameExists(): void
    {
        $request = CreateActRequest::fromArray([
            'actName' => 'Compraventa',
            'actDescription' => 'Acto de compraventa',
        ]);

        $this->actRepository
            ->method('existsByNameAndNotary')
            ->with('Compraventa', 1)
            ->willReturn(true);

        $result = $this->actService->create($request, 1, 1);

        $this->assertEquals('Act name already exists', $result);
    }

    public function testCreateTrimsName(): void
    {
        $request = CreateActRequest::fromArray([
            'actName' => '  Compraventa  ',
            'actDescription' => 'Acto de compraventa',
        ]);

        $this->actRepository
            ->expects($this->once())
            ->method('existsByNameAndNotary')
            ->with('Compraventa', 1)
            ->willReturn(false);

        $this->actRepository
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(function (Act $act) {
                return $act->name === 'Compraventa';
            }))
            ->willReturn(1);

        $this->actService->create($request, 1, 1);
    }

    public function testUpdateSucceedsWithValidData(): void
    {
        $existingAct = $this->createAct(1, 'Compraventa');
        $request = UpdateActRequest::fromArray([
            'actName' => 'Compraventa Modificada',
            'actDescription' => 'Descripción actualizada',
            'status' => 1,
        ]);

        $this->actRepository
            ->method('findByIdAndNotary')
            ->with(1, 1)
            ->willReturn($existingAct);

        $this->actRepository
            ->method('existsByNameAndNotary')
            ->with('Compraventa Modificada', 1, 1)
            ->willReturn(false);

        $this->actRepository
            ->expects($this->once())
            ->method('update');

        $result = $this->actService->update(1, $request, 1, 1);

        $this->assertTrue($result);
    }

    public function testUpdateFailsWhenActNotFound(): void
    {
        $request = UpdateActRequest::fromArray([
            'actName' => 'Test',
            'actDescription' => 'Test',
            'status' => 1,
        ]);

        $this->actRepository
            ->method('findByIdAndNotary')
            ->willReturn(null);

        $result = $this->actService->update(999, $request, 1, 1);

        $this->assertNull($result);
    }

    public function testUpdateFailsWhenNameExistsOnOtherAct(): void
    {
        $existingAct = $this->createAct(1, 'Compraventa');
        $request = UpdateActRequest::fromArray([
            'actName' => 'Donación',
            'actDescription' => 'Cambio de nombre',
            'status' => 1,
        ]);

        $this->actRepository
            ->method('findByIdAndNotary')
            ->willReturn($existingAct);

        $this->actRepository
            ->method('existsByNameAndNotary')
            ->with('Donación', 1, 1)
            ->willReturn(true);

        $result = $this->actService->update(1, $request, 1, 1);

        $this->assertEquals('Act name already exists', $result);
    }

    private function createAct(int $id, string $name, int $status = 1): Act
    {
        return new Act(
            id: $id,
            notaryId: 1,
            name: $name,
            description: 'Test description',
            userId: 1,
            modificationDate: new DateTime(),
            statusId: $status,
        );
    }
}
