<?php

declare(strict_types=1);

namespace Tests\Module\PaymentTax\DTO;

use App\Module\PaymentTax\DTO\PaymentTaxResponse;
use App\Module\PaymentTax\Entity\PaymentTax;
use PHPUnit\Framework\TestCase;

final class PaymentTaxResponseTest extends TestCase
{
    public function testFromEntityReturnsExactKeysWithoutNotaryId(): void
    {
        $result = PaymentTaxResponse::fromEntity(new PaymentTax(5, 1, 'ISR', 2));

        $this->assertSame(['taxId' => 5, 'taxName' => 'ISR', 'status' => 2], $result);
        $this->assertArrayNotHasKey('notaryId', $result);
    }

    public function testFromCollectionMapsEveryEntity(): void
    {
        $result = PaymentTaxResponse::fromCollection([
            new PaymentTax(1, 1, 'ISR', 1),
            new PaymentTax(2, 1, 'IVA', 2),
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('ISR', $result[0]['taxName']);
        $this->assertSame(2, $result[1]['taxId']);
    }

    public function testFromCollectionWithEmptyArray(): void
    {
        $this->assertSame([], PaymentTaxResponse::fromCollection([]));
    }
}
