<?php

declare(strict_types=1);

namespace Tests\Module\User\Entity;

use App\Module\User\Entity\AccessCode;
use PHPUnit\Framework\TestCase;

final class AccessCodeTest extends TestCase
{
    public function testValuesAreInCanonicalOrder(): void
    {
        $this->assertSame(['ESC', 'PAG'], AccessCode::values());
    }

    public function testToCsvUsesCanonicalOrderAndRemovesDuplicates(): void
    {
        $this->assertSame('ESC,PAG', AccessCode::toCsv(['PAG', 'ESC', 'PAG']));
    }

    public function testToCsvWithSingleCode(): void
    {
        $this->assertSame('PAG', AccessCode::toCsv(['PAG']));
    }

    public function testFromCsvTrimsAndSplits(): void
    {
        $this->assertSame(['ESC', 'PAG'], AccessCode::fromCsv('ESC, PAG'));
    }

    public function testFromCsvReturnsEmptyArrayForEmptyOrNull(): void
    {
        $this->assertSame([], AccessCode::fromCsv(''));
        $this->assertSame([], AccessCode::fromCsv(null));
    }

    public function testFromCsvDoesNotFilterUnknownCodes(): void
    {
        $this->assertSame(['ESC', 'XYZ'], AccessCode::fromCsv('ESC,,XYZ'));
    }
}
