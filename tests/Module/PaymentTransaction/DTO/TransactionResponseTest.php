<?php

declare(strict_types=1);

namespace Tests\Module\PaymentTransaction\DTO;

use App\Module\PaymentTransaction\DTO\TransactionResponse;
use App\Module\PaymentTransaction\Entity\PaymentTransaction;
use App\Module\PaymentTransaction\Entity\PaymentTransactionDetail;
use PHPUnit\Framework\TestCase;

final class TransactionResponseTest extends TestCase
{
    public function testExposesExactlyTheContractFieldsWithNumericTypes(): void
    {
        $tx = PaymentTransaction::fromRow([
            'id_transaction' => '9', 'uuid' => 'u1', 'id_notary' => '1', 'id_client' => '5', 'client_name' => 'Ana',
            'id_procedure' => '2', 'procedure_name' => 'Poder', 'date' => '2026-11-11 00:00:00',
            'fee' => '3000', 'taxes' => '1000', 'total' => '4000', 'reference' => 'r', 'responsible' => 'L',
            'comments' => 'c', 'id_status' => '1',
        ], [new PaymentTransactionDetail(1, 'ISR', 2, 250.0, '2026-11-12', 'd')]);

        $body = TransactionResponse::fromEntity($tx);

        $this->assertSame([
            'transactionId', 'uuid', 'clientId', 'clientName', 'procedureId', 'procedureName', 'date',
            'fee', 'taxes', 'total', 'reference', 'responsible', 'comments', 'details',
        ], array_keys($body));
        $this->assertSame(9, $body['transactionId']);
        $this->assertSame(3000.0, $body['fee']);
        $this->assertSame(4000.0, $body['total']);
        $this->assertSame([
            'taxId' => 1, 'taxName' => 'ISR', 'quantity' => 2, 'amount' => 250.0, 'date' => '2026-11-12', 'comments' => 'd',
        ], $body['details'][0]);
    }
}
