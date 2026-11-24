<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\DTO;

use App\Module\PaymentStatement\Entity\ClientStatement;

final class StatementResponse
{
    /**
     * @return array<string, mixed>
     */
    public static function fromEntity(ClientStatement $statement): array
    {
        $transactions = [];
        foreach ($statement->transactions as $t) {
            $transactions[] = [
                'transactionId' => $t->id,
                'uuid' => $t->uuid,
                'date' => $t->date,
                'procedureName' => $t->procedureName,
                'reference' => $t->reference,
                'fee' => $t->fee,
                'taxes' => $t->taxes,
                'total' => $t->total,
            ];
        }

        $payments = [];
        foreach ($statement->payments as $p) {
            $payments[] = [
                'paymentId' => $p->id,
                'uuid' => $p->uuid,
                'date' => $p->date,
                'comment' => $p->comment,
                'amount' => $p->amount,
            ];
        }

        return [
            'clientId' => $statement->clientId,
            'clientName' => $statement->clientName,
            'fromDate' => $statement->fromDate,
            'toDate' => $statement->toDate,
            'transactions' => $transactions,
            'payments' => $payments,
            'pendingAmount' => $statement->pendingAmount,
            'totalCharges' => $statement->totalCharges,
            'totalPayments' => $statement->totalPayments,
            'balance' => $statement->balance,
        ];
    }
}
