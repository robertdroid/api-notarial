<?php

declare(strict_types=1);

namespace Tests\Module\PaymentProcedure\Entity;

use App\Module\PaymentProcedure\Entity\PaymentProcedure;
use PHPUnit\Framework\TestCase;

final class PaymentProcedureTest extends TestCase
{
    public function testFromRowMapsAllFields(): void
    {
        $procedure = PaymentProcedure::fromRow([
            'id_procedure' => '7',
            'id_notary' => '1',
            'procedure_name' => 'Escritura',
            'id_status' => '2',
        ]);

        $this->assertSame(7, $procedure->id);
        $this->assertSame(1, $procedure->notaryId);
        $this->assertSame('Escritura', $procedure->name);
        $this->assertSame(2, $procedure->statusId);
    }

    public function testFromRowDefaultsNullStatusToActive(): void
    {
        $procedure = PaymentProcedure::fromRow([
            'id_procedure' => 1,
            'id_notary' => 1,
            'procedure_name' => 'Escritura',
            'id_status' => null,
        ]);

        $this->assertSame(1, $procedure->statusId);
    }
}
