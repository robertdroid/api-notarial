<?php

declare(strict_types=1);

namespace Tests\Module\PaymentProcedure\DTO;

use App\Module\PaymentProcedure\DTO\PaymentProcedureResponse;
use App\Module\PaymentProcedure\Entity\PaymentProcedure;
use PHPUnit\Framework\TestCase;

final class PaymentProcedureResponseTest extends TestCase
{
    public function testFromEntityReturnsExactKeysWithoutNotaryId(): void
    {
        $result = PaymentProcedureResponse::fromEntity(new PaymentProcedure(5, 1, 'Escritura', 2));

        $this->assertSame(['procedureId' => 5, 'procedureName' => 'Escritura', 'status' => 2], $result);
        $this->assertArrayNotHasKey('notaryId', $result);
    }

    public function testFromCollectionMapsEveryEntity(): void
    {
        $result = PaymentProcedureResponse::fromCollection([
            new PaymentProcedure(1, 1, 'Escritura', 1),
            new PaymentProcedure(2, 1, 'Certificacion', 2),
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('Escritura', $result[0]['procedureName']);
        $this->assertSame(2, $result[1]['procedureId']);
    }

    public function testFromCollectionWithEmptyArray(): void
    {
        $this->assertSame([], PaymentProcedureResponse::fromCollection([]));
    }
}
