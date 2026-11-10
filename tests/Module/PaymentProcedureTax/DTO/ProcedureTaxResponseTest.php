<?php

declare(strict_types=1);

namespace Tests\Module\PaymentProcedureTax\DTO;

use App\Module\PaymentProcedureTax\DTO\ProcedureTaxResponse;
use App\Module\PaymentProcedureTax\Entity\PaymentProcedureTax;
use PHPUnit\Framework\TestCase;

final class ProcedureTaxResponseTest extends TestCase
{
    public function testFromEntityHasExactShapeWithoutStatus(): void
    {
        $entity = new PaymentProcedureTax(2, 9, 'ISR', 500.0, 1);

        $this->assertSame(
            ['procedureId' => 2, 'taxId' => 9, 'taxName' => 'ISR', 'defaultValue' => 500.0],
            ProcedureTaxResponse::fromEntity($entity)
        );
    }

    public function testFromCollectionKeepsOrder(): void
    {
        $result = ProcedureTaxResponse::fromCollection([
            new PaymentProcedureTax(1, 2, 'B', 1.0, 1),
            new PaymentProcedureTax(1, 1, 'A', 2.0, 1),
        ]);

        $this->assertSame([2, 1], array_column($result, 'taxId'));
        $this->assertSame([], ProcedureTaxResponse::fromCollection([]));
    }
}
