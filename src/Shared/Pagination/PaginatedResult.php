<?php

declare(strict_types=1);

namespace App\Shared\Pagination;

/**
 * Immutable page of results with pagination metadata.
 *
 * @template T
 */
final class PaginatedResult
{
    /**
     * @param array<int, T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    public function totalPages(): int
    {
        if ($this->total === 0 || $this->perPage < 1) {
            return 0;
        }

        return (int) ceil($this->total / $this->perPage);
    }

    /**
     * @param callable(T): array<string, mixed> $mapper
     * @return array{data: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    public function toArray(callable $mapper): array
    {
        return [
            'data' => array_map($mapper, $this->items),
            'pagination' => [
                'page' => $this->page,
                'perPage' => $this->perPage,
                'total' => $this->total,
                'totalPages' => $this->totalPages(),
            ],
        ];
    }
}
