<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\DTO;

use App\Module\PaymentTransaction\Entity\PaymentTransaction;
use App\Module\PaymentTransaction\Entity\PaymentTransactionDetail;

final class TransactionResponse
{
    /**
     * @return array<string, mixed>
     */
    public static function fromEntity(PaymentTransaction $transaction): array
    {
        return [
            'transactionId' => $transaction->id,
            'uuid' => $transaction->uuid,
            'clientId' => $transaction->clientId,
            'clientName' => $transaction->clientName,
            'procedureId' => $transaction->procedureId,
            'procedureName' => $transaction->procedureName,
            'date' => $transaction->date,
            'fee' => $transaction->fee,
            'taxes' => $transaction->taxes,
            'total' => $transaction->total,
            'reference' => $transaction->reference,
            'responsible' => $transaction->responsible,
            'comments' => $transaction->comments,
            'details' => array_map(
                static fn (PaymentTransactionDetail $d): array => [
                    'taxId' => $d->taxId,
                    'taxName' => $d->taxName,
                    'quantity' => $d->quantity,
                    'amount' => $d->amount,
                    'date' => $d->date,
                    'comments' => $d->comments,
                ],
                $transaction->details
            ),
        ];
    }
}
