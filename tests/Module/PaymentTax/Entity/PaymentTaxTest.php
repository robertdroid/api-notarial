<?php

declare(strict_types=1);

namespace Tests\Module\PaymentTax\Entity;

use App\Module\PaymentTax\Entity\PaymentTax;
use PHPUnit\Framework\TestCase;

final class PaymentTaxTest extends TestCase
{
    public function testFromRowMapsAllFields(): void
    {
        $tax = PaymentTax::fromRow([
            'id_tax' => '7',
            'id_notary' => '1',
            'tax_name' => 'ISR',
            'id_status' => '2',
        ]);

        $this->assertSame(7, $tax->id);
        $this->assertSame(1, $tax->notaryId);
        $this->assertSame('ISR', $tax->name);
        $this->assertSame(2, $tax->statusId);
    }

    public function testFromRowHandlesNullTaxName(): void
    {
        $tax = PaymentTax::fromRow([
            'id_tax' => 1,
            'id_notary' => 1,
            'tax_name' => null,
            'id_status' => 1,
        ]);

        $this->assertSame('', $tax->name);
    }

    public function testFromRowDefaultsNullStatusToActive(): void
    {
        $tax = PaymentTax::fromRow([
            'id_tax' => 1,
            'id_notary' => 1,
            'tax_name' => 'ISR',
            'id_status' => null,
        ]);

        $this->assertSame(1, $tax->statusId);
    }
}
