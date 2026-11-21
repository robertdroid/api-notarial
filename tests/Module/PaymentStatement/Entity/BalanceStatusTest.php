<?php

declare(strict_types=1);

namespace Tests\Module\PaymentStatement\Entity;

use App\Module\PaymentStatement\Entity\BalanceStatus;
use PHPUnit\Framework\TestCase;

final class BalanceStatusTest extends TestCase
{
    /** @return array<string, array{float, BalanceStatus, string}> */
    public static function balances(): array
    {
        return [
            'positive' => [800.0, BalanceStatus::Pending, 'SALDO PENDIENTE'],
            'one cent' => [0.01, BalanceStatus::Pending, 'SALDO PENDIENTE'],
            'negative' => [-300.0, BalanceStatus::InFavor, 'SALDO A FAVOR'],
            'minus one cent' => [-0.01, BalanceStatus::InFavor, 'SALDO A FAVOR'],
            'zero' => [0.0, BalanceStatus::Settled, 'SIN ADEUDO'],
            'negative zero' => [-0.0, BalanceStatus::Settled, 'SIN ADEUDO'],
            'float drift below a cent, negative' => [-0.001, BalanceStatus::Settled, 'SIN ADEUDO'],
            'float drift below a cent, positive' => [0.004, BalanceStatus::Settled, 'SIN ADEUDO'],
        ];
    }

    /** @dataProvider balances */
    public function testFromBalance(float $balance, BalanceStatus $expected, string $label): void
    {
        $status = BalanceStatus::fromBalance($balance);

        $this->assertSame($expected, $status);
        $this->assertSame($label, $status->label());
    }
}
