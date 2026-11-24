<?php

declare(strict_types=1);

namespace Tests\Module\Payment\Entity;

use App\Module\Payment\Entity\Payment;
use PHPUnit\Framework\TestCase;

final class PaymentTest extends TestCase
{
    public function testFromRowMapsAndCastsColumns(): void
    {
        $payment = Payment::fromRow([
            'id_payment' => '19', 'uuid' => 'Us2Gvq', 'id_notary' => '1', 'id_client' => '5',
            'amount' => '5000.5', 'payment_date' => '2024-04-02 00:00:00', 'comments' => 'Adelanto',
            'client_name' => 'Ana Pérez',
        ]);

        $this->assertSame(19, $payment->id);
        $this->assertSame('Us2Gvq', $payment->uuid);
        $this->assertSame(1, $payment->notaryId);
        $this->assertSame(5, $payment->clientId);
        $this->assertSame('Ana Pérez', $payment->clientName);
        $this->assertSame(5000.5, $payment->amount);
        $this->assertSame('2024-04-02', $payment->paymentDate);
        $this->assertSame('Adelanto', $payment->comments);
    }

    public function testLegacyNullsBecomeNullDateAndEmptyComments(): void
    {
        $payment = Payment::fromRow([
            'id_payment' => 1, 'uuid' => 'abc', 'id_notary' => 1, 'id_client' => 5,
            'amount' => 10, 'payment_date' => null, 'comments' => null, 'client_name' => null,
        ]);

        $this->assertNull($payment->paymentDate);
        $this->assertSame('', $payment->comments);
        $this->assertSame('', $payment->clientName);
    }

    public function testAmountWithMoreThanTwoDecimalsIsNotRounded(): void
    {
        $payment = Payment::fromRow([
            'id_payment' => 1, 'uuid' => 'abc', 'id_notary' => 1, 'id_client' => 5,
            'amount' => 10.555, 'payment_date' => '2024-04-02 00:00:00', 'comments' => '',
        ]);

        $this->assertSame(10.555, $payment->amount);
    }
}
