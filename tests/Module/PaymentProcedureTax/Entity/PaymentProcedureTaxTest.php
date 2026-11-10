<?php

declare(strict_types=1);

namespace Tests\Module\PaymentProcedureTax\Entity;

use App\Module\PaymentProcedureTax\Entity\PaymentProcedureTax;
use PHPUnit\Framework\TestCase;

final class PaymentProcedureTaxTest extends TestCase
{
    public function testFromRowCastsPdoStringTypes(): void
    {
        $tax = PaymentProcedureTax::fromRow([
            'id_procedure' => '3',
            'id_tax' => '7',
            'tax_name' => 'ISR',
            'default_value' => '500.5',
            'id_status' => '1',
        ]);

        $this->assertSame(3, $tax->procedureId);
        $this->assertSame(7, $tax->taxId);
        $this->assertSame('ISR', $tax->taxName);
        $this->assertSame(500.5, $tax->defaultValue);
        $this->assertSame(1, $tax->statusId);
    }

    public function testFromRowDefaultsNullStatusToActive(): void
    {
        $tax = PaymentProcedureTax::fromRow([
            'id_procedure' => 1,
            'id_tax' => 1,
            'tax_name' => 'ISR',
            'default_value' => 0,
            'id_status' => null,
        ]);

        $this->assertSame(PaymentProcedureTax::STATUS_ACTIVE, $tax->statusId);
    }
}
