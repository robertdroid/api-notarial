<?php

declare(strict_types=1);

namespace App\Module\Deed\Service;

use App\Module\Deed\DTO\DeedListQuery;
use App\Module\Deed\Entity\DeedListItem;
use App\Module\Deed\Repository\DeedRepository;
use App\Shared\Pagination\PaginatedResult;

class DeedService
{
    public const PER_PAGE = 100;

    public function __construct(
        private readonly DeedRepository $repository
    ) {}

    /**
     * @return PaginatedResult<DeedListItem>
     */
    public function list(int $notaryId, DeedListQuery $query): PaginatedResult
    {
        $offset = ($query->page - 1) * self::PER_PAGE;
        $total = $this->repository->countByNotary($notaryId, $query->words);

        $items = $offset < $total
            ? $this->repository->findPageByNotary($notaryId, $query->words, self::PER_PAGE, $offset)
            : [];

        return new PaginatedResult($items, $total, $query->page, self::PER_PAGE);
    }
}
