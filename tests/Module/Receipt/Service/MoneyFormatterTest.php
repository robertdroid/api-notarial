<?php

declare(strict_types=1);

namespace Tests\Module\Receipt\Service;

use App\Module\Receipt\Service\MoneyFormatter;
use PHPUnit\Framework\TestCase;

final class MoneyFormatterTest extends TestCase
{
    public function testFormatsMoneyWithThousandsSeparatorAndTwoDecimals(): void
    {
        $this->assertSame('$1,350.00', MoneyFormatter::money(1350.0));
        $this->assertSame('$0.00', MoneyFormatter::money(0.0));
        $this->assertSame('$999,999,999.99', MoneyFormatter::money(999999999.99));
        $this->assertSame('$0.10', MoneyFormatter::money(0.1));
    }

    public function testMoneyDoesNotDependOnLocale(): void
    {
        $previous = setlocale(LC_NUMERIC, 0);
        setlocale(LC_NUMERIC, 'es_MX.UTF-8', 'es_ES.UTF-8', 'de_DE.UTF-8');

        try {
            $this->assertSame('$1,350.50', MoneyFormatter::money(1350.5));
        } finally {
            setlocale(LC_NUMERIC, (string) $previous);
        }
    }

    public function testFormatsDateAsDayMonthYear(): void
    {
        $this->assertSame('02/04/2024', MoneyFormatter::date('2024-04-02'));
        $this->assertSame('02/04/2024', MoneyFormatter::date('2024-04-02 13:45:00'));
    }

    public function testDateFallsBackWhenMissingOrInvalid(): void
    {
        $this->assertSame('—', MoneyFormatter::date(null));
        $this->assertSame('—', MoneyFormatter::date(''));
        $this->assertSame('Sin fecha', MoneyFormatter::date(null, 'Sin fecha'));
        $this->assertSame('Sin fecha', MoneyFormatter::date('0000-00-00', 'Sin fecha'));
        $this->assertSame('—', MoneyFormatter::date('2024-02-31'));
        $this->assertSame('—', MoneyFormatter::date('not a date'));
    }
}
