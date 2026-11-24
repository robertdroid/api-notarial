<?php

declare(strict_types=1);

namespace Tests\Module\Payment\DTO;

use App\Module\Payment\DTO\PaymentResponse;
use App\Module\Payment\Entity\Payment;
use PHPUnit\Framework\TestCase;

final class PaymentResponseTest extends TestCase
{
    public function testExposesTheApiFieldsAndNeverTheNotary(): void
    {
        $payment = new Payment(19, 'Us2Gvq', 1, 5, 'Ana Pérez', 5000.5, '2024-04-02', 'Adelanto');

        $this->assertSame([
            'paymentId' => 19,
            'uuid' => 'Us2Gvq',
            'clientId' => 5,
            'clientName' => 'Ana Pérez',
            'amount' => 5000.5,
            'paymentDate' => '2024-04-02',
            'comments' => 'Adelanto',
        ], PaymentResponse::fromEntity($payment));
    }

    public function testLegacyPaymentWithoutDateReturnsNull(): void
    {
        $payment = new Payment(1, 'abc', 1, 5, 'Ana', 10.0, null, '');

        $this->assertNull(PaymentResponse::fromEntity($payment)['paymentDate']);
    }
}
