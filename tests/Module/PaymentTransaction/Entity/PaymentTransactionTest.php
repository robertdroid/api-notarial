<?php

declare(strict_types=1);

namespace Tests\Module\PaymentTransaction\Entity;

use App\Module\PaymentTransaction\Entity\PaymentTransaction;
use App\Module\PaymentTransaction\Entity\PaymentTransactionDetail;
use PHPUnit\Framework\TestCase;

final class PaymentTransactionTest extends TestCase
{
    public function testFromRowMapsPdoStringsAndStripsTheTimeFromDate(): void
    {
        $tx = PaymentTransaction::fromRow([
            'id_transaction' => '7',
            'uuid' => 'AbC_1',
            'id_notary' => '1',
            'id_client' => '5',
            'client_name' => 'Ana',
            'id_procedure' => '2',
            'procedure_name' => 'Poder',
            'date' => '2026-11-11 00:00:00',
            'fee' => '1000.5',
            'taxes' => '10',
            'total' => '1010.5',
            'reference' => 'ref',
            'responsible' => 'Luis',
            'comments' => 'c',
            'id_status' => '1',
        ], []);

        $this->assertSame(7, $tx->id);
        $this->assertSame('AbC_1', $tx->uuid);
        $this->assertSame(5, $tx->clientId);
        $this->assertSame('2026-11-11', $tx->date);
        $this->assertSame(1000.5, $tx->fee);
        $this->assertSame(1010.5, $tx->total);
        $this->assertSame([], $tx->details);
    }

    public function testFromRowToleratesLegacyNulls(): void
    {
        $tx = PaymentTransaction::fromRow([
            'id_transaction' => 1, 'uuid' => 'u', 'id_notary' => 1, 'id_client' => 99, 'client_name' => null,
            'id_procedure' => 3, 'procedure_name' => null, 'date' => '2026-01-02 00:00:00',
            'fee' => 0, 'taxes' => 0, 'total' => 0, 'reference' => null, 'responsible' => null,
            'comments' => null, 'id_status' => null,
        ], []);

        $this->assertSame('', $tx->clientName);
        $this->assertSame('', $tx->procedureName);
        $this->assertSame('', $tx->reference);
        $this->assertSame('', $tx->comments);
        $this->assertSame(PaymentTransaction::STATUS_ACTIVE, $tx->statusId);
    }

    public function testDetailTaxIdsReturnsTheTaxIdOfEachDetail(): void
    {
        $details = [
            new PaymentTransactionDetail(3, 'A', 1, 1.0, null, ''),
            new PaymentTransactionDetail(8, 'B', 1, 1.0, null, ''),
        ];
        $tx = PaymentTransaction::fromRow([
            'id_transaction' => 1, 'uuid' => 'u', 'id_notary' => 1, 'id_client' => 1, 'id_procedure' => 1,
            'date' => '2026-01-02 00:00:00', 'fee' => 0, 'taxes' => 0, 'total' => 0,
        ], $details);

        $this->assertSame([3, 8], $tx->detailTaxIds());
    }
}
