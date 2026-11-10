<?php

declare(strict_types=1);

namespace Tests\Shared\Pagination;

use App\Shared\Pagination\PaginatedResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaginatedResultTest extends TestCase
{
    #[DataProvider('totalPagesProvider')]
    public function testTotalPages(int $total, int $expected): void
    {
        $result = new PaginatedResult([], $total, 1, 100);

        $this->assertSame($expected, $result->totalPages());
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function totalPagesProvider(): array
    {
        return [
            'empty' => [0, 0],
            'exactly one page' => [100, 1],
            'one over' => [101, 2],
            'large' => [13372, 134],
        ];
    }

    public function testToArrayAppliesMapperAndBuildsPagination(): void
    {
        $result = new PaginatedResult([1, 2], 250, 2, 100);

        $array = $result->toArray(static fn (int $n): array => ['n' => $n * 10]);

        $this->assertSame([['n' => 10], ['n' => 20]], $array['data']);
        $this->assertSame(
            ['page' => 2, 'perPage' => 100, 'total' => 250, 'totalPages' => 3],
            $array['pagination']
        );
    }
}
