<?php

declare(strict_types=1);

namespace Tests\Module\Deed\Service;

use App\Module\Deed\DTO\DeedListQuery;
use App\Module\Deed\Entity\DeedListItem;
use App\Module\Deed\Repository\DeedRepository;
use App\Module\Deed\Service\DeedService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DeedServiceTest extends TestCase
{
    private DeedRepository&MockObject $repository;
    private DeedService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(DeedRepository::class);
        $this->service = new DeedService($this->repository);
    }

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
}
