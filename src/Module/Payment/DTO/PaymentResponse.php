<?php

declare(strict_types=1);

namespace App\Module\Payment\DTO;

use App\Module\Payment\Entity\Payment;

final class PaymentResponse
{
    /**
     * @return array<string, mixed>
     */
    public static function fromEntity(Payment $payment): array
    {
        return [
            'paymentId' => $payment->id,
            'uuid' => $payment->uuid,
            'clientId' => $payment->clientId,
            'clientName' => $payment->clientName,
            'amount' => $payment->amount,
            'paymentDate' => $payment->paymentDate,
            'comments' => $payment->comments,
        ];
    }
}
