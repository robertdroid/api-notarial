<?php

declare(strict_types=1);

namespace Tests\Module\PaymentTransaction\Entity;

use App\Module\PaymentTransaction\Entity\PaymentTransactionDetail;
use PHPUnit\Framework\TestCase;

final class PaymentTransactionDetailTest extends TestCase
{
    public function testFromRowMapsValuesAndFormatsDate(): void
    {
        $detail = PaymentTransactionDetail::fromRow([
            'id_tax' => '4', 'tax_name' => 'ISR', 'quantity' => '2', 'amount' => '250.5',
            'date' => '2026-03-04 00:00:00', 'comments' => 'x',
        ]);

        $this->assertSame(4, $detail->taxId);
        $this->assertSame('ISR', $detail->taxName);
        $this->assertSame(2, $detail->quantity);
        $this->assertSame(250.5, $detail->amount);
        $this->assertSame('2026-03-04', $detail->date);
        $this->assertSame('x', $detail->comments);
    }

    public function testFromRowToleratesLegacyNulls(): void
    {
        $detail = PaymentTransactionDetail::fromRow([
            'id_tax' => 4, 'tax_name' => null, 'quantity' => null, 'amount' => null, 'date' => null, 'comments' => null,
        ]);

        $this->assertSame('', $detail->taxName);
        $this->assertSame(1, $detail->quantity);
        $this->assertSame(0.0, $detail->amount);
        $this->assertNull($detail->date);
        $this->assertSame('', $detail->comments);
    }
}
