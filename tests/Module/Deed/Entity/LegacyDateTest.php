<?php

declare(strict_types=1);

namespace Tests\Module\Deed\Entity;

use App\Module\Deed\Entity\LegacyDate;
use PHPUnit\Framework\TestCase;

final class LegacyDateTest extends TestCase
{
    public function testNullEmptyAndZeroDatesBecomeNull(): void
    {
        $this->assertNull(LegacyDate::toYmd(null));
        $this->assertNull(LegacyDate::toYmd(''));
        $this->assertNull(LegacyDate::toYmd('0000-00-00'));
        $this->assertNull(LegacyDate::toYmd('0000-00-00 00:00:00'));
    }

    public function testRealDateIsReturnedAsYmd(): void
    {
        $this->assertSame('2024-01-15', LegacyDate::toYmd('2024-01-15'));
        $this->assertSame('2024-01-15', LegacyDate::toYmd('2024-01-15 10:30:00'));
    }
}
