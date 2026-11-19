<?php

declare(strict_types=1);

namespace Tests\Module\PaymentStatement\DTO;

use App\Module\PaymentStatement\DTO\StatementResponse;
use App\Module\PaymentStatement\Entity\ClientStatement;
use App\Module\PaymentStatement\Entity\StatementPayment;
use App\Module\PaymentStatement\Entity\StatementTransaction;
use PHPUnit\Framework\TestCase;

final class StatementResponseTest extends TestCase
{
    public function testExactFieldNamesOfTheV1Contract(): void
    {
        $statement = new ClientStatement(
            clientId: 5,
            clientName: 'María García López',
            fromDate: '2026-03-01',
            toDate: '2026-03-31',
            transactions: [new StatementTransaction(25, 'Us2Gvq', '2026-03-10', 'Trámite de Compraventa', 'ESC-123', 1000.0, 160.0, 1160.0)],
            payments: [new StatementPayment(10, 'abc123xyz', '2026-03-15', 'Anticipo', 500.0)],
            pendingAmount: 0.0,
            totalCharges: 1160.0,
            totalPayments: 500.0,
            balance: 660.0,
        );

        $this->assertSame([
            'clientId' => 5,
            'clientName' => 'María García López',
            'fromDate' => '2026-03-01',
            'toDate' => '2026-03-31',
            'transactions' => [[
                'transactionId' => 25,
                'uuid' => 'Us2Gvq',
                'date' => '2026-03-10',
                'procedureName' => 'Trámite de Compraventa',
                'reference' => 'ESC-123',
                'fee' => 1000.0,
                'taxes' => 160.0,
                'total' => 1160.0,
            ]],
            'payments' => [[
                'paymentId' => 10,
                'uuid' => 'abc123xyz',
                'date' => '2026-03-15',
                'comment' => 'Anticipo',
                'amount' => 500.0,
            ]],
            'pendingAmount' => 0.0,
            'totalCharges' => 1160.0,
            'totalPayments' => 500.0,
            'balance' => 660.0,
        ], StatementResponse::fromEntity($statement));
    }

    public function testUndatedPaymentAndNullRangeDates(): void
    {
        $statement = new ClientStatement(
            clientId: 5,
            clientName: 'Cliente',
            fromDate: null,
            toDate: null,
            transactions: [],
            payments: [new StatementPayment(11, 'u', null, '', 100.0)],
            pendingAmount: 0.0,
            totalCharges: 0.0,
            totalPayments: 100.0,
            balance: -100.0,
        );

        $result = StatementResponse::fromEntity($statement);

        $this->assertNull($result['fromDate']);
        $this->assertNull($result['toDate']);
        $this->assertSame([], $result['transactions']);
        $this->assertNull($result['payments'][0]['date']);
        $this->assertArrayNotHasKey('comments', $result['payments'][0]);
    }
}
