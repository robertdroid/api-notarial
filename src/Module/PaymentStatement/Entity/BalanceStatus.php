<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\Entity;

enum BalanceStatus: string
{
    case Pending = 'pending';
    case InFavor = 'in_favor';
    case Settled = 'settled';

    /** Compared in integer cents, so a drift such as -0.001 is "no debt" and never "in favor". */
    public static function fromBalance(float $balance): self
    {
        $cents = (int) round($balance * 100);

        return match (true) {
            $cents > 0 => self::Pending,
            $cents < 0 => self::InFavor,
            default => self::Settled,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'SALDO PENDIENTE',
            self::InFavor => 'SALDO A FAVOR',
            self::Settled => 'SIN ADEUDO',
        };
    }
}
