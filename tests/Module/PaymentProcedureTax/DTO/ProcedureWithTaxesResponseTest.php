<?php

declare(strict_types=1);

namespace Tests\Module\PaymentProcedureTax\DTO;

use App\Module\PaymentProcedureTax\DTO\ProcedureWithTaxesResponse;
use App\Module\PaymentProcedureTax\Entity\PaymentProcedureTax;
use App\Module\PaymentProcedureTax\Entity\ProcedureWithTaxes;
use PHPUnit\Framework\TestCase;

final class ProcedureWithTaxesResponseTest extends TestCase
{
    public function testFromEntityHasExactShapeWithoutProcedureIdOrStatusInTaxes(): void
    {
        $entity = new ProcedureWithTaxes(2, 'Compraventa', [
            new PaymentProcedureTax(2, 9, 'ISR', 500.0, 1),
            new PaymentProcedureTax(2, 4, 'IVA', 1200.5, 1),
        ]);

        $this->assertSame(
            [
                'procedureId' => 2,
                'procedureName' => 'Compraventa',
                'taxes' => [
                    ['taxId' => 9, 'taxName' => 'ISR', 'defaultValue' => 500.0],
                    ['taxId' => 4, 'taxName' => 'IVA', 'defaultValue' => 1200.5],
                ],
            ],
            ProcedureWithTaxesResponse::fromEntity($entity)
        );
    }

    public function testFromEntityWithoutTaxesReturnsEmptyList(): void
    {
        $result = ProcedureWithTaxesResponse::fromEntity(new ProcedureWithTaxes(3, 'Donación', []));

        $this->assertSame([], $result['taxes']);
        $this->assertSame('[]', json_encode($result['taxes']));
    }

    public function testZeroDefaultValueIsKept(): void
    {
        $result = ProcedureWithTaxesResponse::fromEntity(
            new ProcedureWithTaxes(1, 'P', [new PaymentProcedureTax(1, 5, 'Tax', 0.0, 1)])
        );

        $this->assertArrayHasKey('defaultValue', $result['taxes'][0]);
        $this->assertSame(0.0, $result['taxes'][0]['defaultValue']);
    }

    public function testTaxesIsAlwaysAListEvenWithNonSequentialKeys(): void
    {
        $result = ProcedureWithTaxesResponse::fromEntity(
            new ProcedureWithTaxes(1, 'P', [7 => new PaymentProcedureTax(1, 5, 'Tax', 1.0, 1)])
        );

        $this->assertTrue(array_is_list($result['taxes']));
        $this->assertStringStartsWith('[', json_encode($result['taxes']));
    }

    public function testFromCollectionKeepsOrderAndHandlesEmpty(): void
    {
        $result = ProcedureWithTaxesResponse::fromCollection([
            new ProcedureWithTaxes(2, 'B', []),
            new ProcedureWithTaxes(1, 'A', []),
        ]);

        $this->assertSame([2, 1], array_column($result, 'procedureId'));
        $this->assertSame([], ProcedureWithTaxesResponse::fromCollection([]));
    }
}
