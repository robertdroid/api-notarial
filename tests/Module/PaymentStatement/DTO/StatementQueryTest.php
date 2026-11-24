<?php

declare(strict_types=1);

namespace Tests\Module\PaymentStatement\DTO;

use App\Module\PaymentStatement\DTO\StatementQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatementQueryTest extends TestCase
{
    public function testNoParamsIsValidAndUnbounded(): void
    {
        $query = StatementQuery::fromArray([]);

        $this->assertTrue($query->isValid());
        $this->assertNull($query->from);
        $this->assertNull($query->to);
        $this->assertNull($query->fromBoundary());
        $this->assertNull($query->toExclusiveBoundary());
    }

    public function testOnlyFrom(): void
    {
        $query = StatementQuery::fromArray(['from' => '2026-03-01']);

        $this->assertTrue($query->isValid());
        $this->assertSame('2026-03-01', $query->from);
        $this->assertNull($query->to);
        $this->assertSame('2026-03-01 00:00:00', $query->fromBoundary());
        $this->assertNull($query->toExclusiveBoundary());
    }

    public function testOnlyTo(): void
    {
        $query = StatementQuery::fromArray(['to' => '2026-03-31']);

        $this->assertTrue($query->isValid());
        $this->assertNull($query->from);
        $this->assertSame('2026-03-31', $query->to);
        $this->assertNull($query->fromBoundary());
        $this->assertSame('2026-04-01 00:00:00', $query->toExclusiveBoundary());
    }

    public function testEmptyAndNullAreTreatedAsNotSent(): void
    {
        $query = StatementQuery::fromArray(['from' => '', 'to' => null]);

        $this->assertTrue($query->isValid());
        $this->assertNull($query->from);
        $this->assertNull($query->to);
    }

    public function testUnknownParamsAreIgnored(): void
    {
        $query = StatementQuery::fromArray(['foo' => 'bar', 'from' => '2026-03-01']);

        $this->assertTrue($query->isValid());
        $this->assertSame('2026-03-01', $query->from);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidDates(): array
    {
        return [
            'dd/mm/yyyy' => ['01/03/2026'],
            'no zero padding' => ['2026-3-1'],
            'nonexistent day' => ['2026-02-31'],
            'array' => [['x']],
            'garbage' => ['abc'],
            'with time' => ['2026-03-01 10:00:00'],
        ];
    }

    #[DataProvider('invalidDates')]
    public function testInvalidFrom(mixed $value): void
    {
        $query = StatementQuery::fromArray(['from' => $value]);

        $this->assertFalse($query->isValid());
        $this->assertSame(['from' => 'From must be a valid date in YYYY-MM-DD format'], $query->getErrors());
    }

    #[DataProvider('invalidDates')]
    public function testInvalidTo(mixed $value): void
    {
        $query = StatementQuery::fromArray(['to' => $value]);

        $this->assertFalse($query->isValid());
        $this->assertSame(['to' => 'To must be a valid date in YYYY-MM-DD format'], $query->getErrors());
    }

    public function testFromAfterToIsAnErrorOnTo(): void
    {
        $query = StatementQuery::fromArray(['from' => '2026-03-31', 'to' => '2026-03-01']);

        $this->assertFalse($query->isValid());
        $this->assertSame(['to' => 'To must not be before from'], $query->getErrors());
    }

    public function testFromEqualToIsValid(): void
    {
        $query = StatementQuery::fromArray(['from' => '2026-03-15', 'to' => '2026-03-15']);

        $this->assertTrue($query->isValid());
        $this->assertSame('2026-03-15 00:00:00', $query->fromBoundary());
        $this->assertSame('2026-03-16 00:00:00', $query->toExclusiveBoundary());
    }

    public function testBothInvalidReportBothErrorsAndNoRangeError(): void
    {
        $query = StatementQuery::fromArray(['from' => '2026-02-31', 'to' => 'nope']);

        $this->assertSame([
            'from' => 'From must be a valid date in YYYY-MM-DD format',
            'to' => 'To must be a valid date in YYYY-MM-DD format',
        ], $query->getErrors());
    }

    public function testOneInvalidDateSkipsTheRangeComparison(): void
    {
        $query = StatementQuery::fromArray(['from' => '2026-02-31', 'to' => '2026-01-01']);

        $this->assertSame(['from' => 'From must be a valid date in YYYY-MM-DD format'], $query->getErrors());
    }

    /** @return array<string, array{string, string}> */
    public static function exclusiveBoundaries(): array
    {
        return [
            'month crossing' => ['2026-03-31', '2026-04-01 00:00:00'],
            'year crossing' => ['2026-12-31', '2027-01-01 00:00:00'],
            'leap day' => ['2028-02-28', '2028-02-29 00:00:00'],
            'end of leap february' => ['2028-02-29', '2028-03-01 00:00:00'],
            'regular day' => ['2026-03-10', '2026-03-11 00:00:00'],
        ];
    }

    #[DataProvider('exclusiveBoundaries')]
    public function testToExclusiveBoundary(string $to, string $expected): void
    {
        $this->assertSame($expected, StatementQuery::fromArray(['to' => $to])->toExclusiveBoundary());
    }
}
