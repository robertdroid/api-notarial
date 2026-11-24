<?php

declare(strict_types=1);

namespace Tests\Module\Deed\DTO;

use App\Module\Deed\DTO\DeedListQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeedListQueryTest extends TestCase
{
    public function testPageDefaultsToOne(): void
    {
        $query = DeedListQuery::fromQueryParams(null, null);

        $this->assertTrue($query->isValid());
        $this->assertSame(1, $query->page);
        $this->assertSame([], $query->words);
    }

    public function testValidPageIsParsed(): void
    {
        $query = DeedListQuery::fromQueryParams('25', null);

        $this->assertTrue($query->isValid());
        $this->assertSame(25, $query->page);
    }

    #[DataProvider('invalidPageProvider')]
    public function testInvalidPageIsRejected(string $page): void
    {
        $query = DeedListQuery::fromQueryParams($page, null);

        $this->assertFalse($query->isValid());
        $this->assertArrayHasKey('page', $query->getErrors());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPageProvider(): array
    {
        return [
            'non numeric' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-1'],
            'decimal' => ['1.5'],
            'exponent' => ['1e3'],
            'empty' => [''],
            'too long' => ['12345678901'],
        ];
    }

    public function testSearchIsSplitIntoWordsIgnoringExtraSpaces(): void
    {
        $query = DeedListQuery::fromQueryParams(null, "  perez   juan \t lopez ");

        $this->assertSame(['perez', 'juan', 'lopez'], $query->words);
    }

    public function testEmptyOrBlankSearchYieldsNoWords(): void
    {
        $this->assertSame([], DeedListQuery::fromQueryParams(null, '')->words);
        $this->assertSame([], DeedListQuery::fromQueryParams(null, '    ')->words);
    }

    public function testSearchIsLimitedToTenWords(): void
    {
        $query = DeedListQuery::fromQueryParams(null, implode(' ', range(1, 15)));

        $this->assertCount(DeedListQuery::MAX_WORDS, $query->words);
        $this->assertSame('10', $query->words[9]);
    }

    public function testEachWordIsTruncatedToMaxLength(): void
    {
        $query = DeedListQuery::fromQueryParams(null, str_repeat('á', 150));

        $this->assertSame(DeedListQuery::MAX_WORD_LENGTH, mb_strlen($query->words[0]));
    }
}
