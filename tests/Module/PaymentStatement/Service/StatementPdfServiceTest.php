<?php

declare(strict_types=1);

namespace Tests\Module\PaymentStatement\Service;

use App\Module\Notary\Entity\Notary;
use App\Module\Notary\Service\NotaryService;
use App\Module\PaymentClient\Entity\PaymentClient;
use App\Module\PaymentClient\Repository\PaymentClientRepository;
use App\Module\PaymentStatement\DTO\StatementLedgerRow;
use App\Module\PaymentStatement\DTO\StatementQuery;
use App\Module\PaymentStatement\Entity\BalanceStatus;
use App\Module\PaymentStatement\Entity\ClientStatement;
use App\Module\PaymentStatement\Entity\LedgerRowKind;
use App\Module\PaymentStatement\Entity\StatementPayment;
use App\Module\PaymentStatement\Entity\StatementTransaction;
use App\Module\PaymentStatement\Exception\StatementGenerationException;
use App\Module\PaymentStatement\Repository\StatementRepository;
use App\Module\PaymentStatement\Service\PaymentStatementService;
use App\Module\PaymentStatement\Service\StatementLedgerBuilder;
use App\Module\PaymentStatement\Service\StatementPdfService;
use App\Module\Receipt\Service\AmountInWordsConverter;
use App\Shared\Pdf\LogoDataUriReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * PaymentStatementService and LogoDataUriReader are final, so they are built for real over mocked
 * repositories and a mocked NotaryService (the same approach as ReceiptServiceTest).
 */
final class StatementPdfServiceTest extends TestCase
{
    private const NOTARY = 1;
    private const CLIENT = 5;

    private PaymentClientRepository&MockObject $clientRepository;
    private StatementRepository&MockObject $statementRepository;
    private NotaryService&MockObject $notaryService;

    private string|false $previousErrorLog = false;
    private ?string $logFile = null;

    protected function setUp(): void
    {
        $this->clientRepository = $this->createMock(PaymentClientRepository::class);
        $this->statementRepository = $this->createMock(StatementRepository::class);
        $this->notaryService = $this->createMock(NotaryService::class);

        // error_log() goes to a file so tests can assert on what was reported
        $this->logFile = tempnam(sys_get_temp_dir(), 'statement-log');
        $this->previousErrorLog = ini_set('error_log', (string) $this->logFile);
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }
        if ($this->logFile !== null && is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    // ---- helpers ---------------------------------------------------------

    private function service(?StatementLedgerBuilder $builder = null): StatementPdfService
    {
        return new StatementPdfService(
            new PaymentStatementService($this->clientRepository, $this->statementRepository),
            $this->clientRepository,
            $this->notaryService,
            new LogoDataUriReader($this->notaryService),
            $builder ?? new StatementLedgerBuilder(),
            new AmountInWordsConverter(),
            // 2024-04-02 03:30 UTC is still 01/04 21:30 in Mexico City
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2024-04-02 03:30:00', new \DateTimeZone('UTC')),
        );
    }

    private function client(string $phone = '5551234567', string $email = 'maria@example.com'): PaymentClient
    {
        return new PaymentClient(self::CLIENT, self::NOTARY, 'María García López', $phone, $email, PaymentClient::STATUS_ACTIVE);
    }

    private function notary(string $logo = ''): Notary
    {
        return new Notary(self::NOTARY, 'Notaría 42', 'Lic. Juan Pérez', 'Querétaro', 'Av. Central 100', '4421234567', 'XAXX010101000', $logo, 1);
    }

    private function givenNotary(?Notary $notary = null): void
    {
        $this->notaryService->method('getByIdActive')->with(self::NOTARY)->willReturn($notary ?? $this->notary());
    }

    /**
     * @param list<StatementTransaction> $transactions
     * @param list<StatementPayment>     $payments
     */
    private function givenMovements(array $transactions, array $payments): void
    {
        $this->statementRepository->method('findActiveTransactions')->willReturn($transactions);
        $this->statementRepository->method('findPayments')->willReturn($payments);
    }

    private function transaction(float $total, int $id = 25, string $date = '2026-03-10'): StatementTransaction
    {
        return new StatementTransaction($id, 'uuid' . $id, $date, 'Compraventa', 'ESC-1', $total, 0.0, $total);
    }

    private function payment(float $amount, int $id = 10, ?string $date = '2026-03-15'): StatementPayment
    {
        return new StatementPayment($id, 'p' . $id, $date, 'Anticipo', $amount);
    }

    private function query(array $params = []): StatementQuery
    {
        return StatementQuery::fromArray($params);
    }

    private function logContents(): string
    {
        return (string) file_get_contents((string) $this->logFile);
    }

    // ---- client ----------------------------------------------------------

    public function testUnknownClientReturnsNullWithoutAnyOtherQuery(): void
    {
        // The only client read is the one PaymentStatementService makes to confirm the client exists
        $this->clientRepository->expects($this->once())->method('findByIdAndNotary')->with(self::CLIENT, self::NOTARY)->willReturn(null);
        $this->notaryService->expects($this->never())->method('getByIdActive');
        $this->notaryService->expects($this->never())->method('getLogoInfo');
        $this->statementRepository->expects($this->never())->method('findActiveTransactions');
        $this->statementRepository->expects($this->never())->method('findPayments');

        $this->assertNull($this->service()->build(self::CLIENT, self::NOTARY, $this->query()));
    }

    public function testClientPhoneAndEmailComeFromTheSecondRead(): void
    {
        $this->clientRepository->method('findByIdAndNotary')->with(self::CLIENT, self::NOTARY)->willReturn($this->client());
        $this->givenMovements([], []);
        $this->givenNotary();

        $data = $this->service()->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertNotNull($data);
        $this->assertSame('María García López', $data->client->name);
        $this->assertSame('5551234567', $data->client->phone);
        $this->assertSame('maria@example.com', $data->client->email);
        $this->assertSame(self::CLIENT, $data->clientId);
    }

    public function testClientWithoutPhoneAndEmailKeepsThemEmpty(): void
    {
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client('', ''));
        $this->givenMovements([], []);
        $this->givenNotary();

        $data = $this->service()->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertSame('', $data->client->phone);
        $this->assertSame('', $data->client->email);
    }

    public function testClientDeletedBetweenReadsFallsBackToTheStatementName(): void
    {
        $calls = 0;
        $this->clientRepository->method('findByIdAndNotary')->willReturnCallback(
            function () use (&$calls): ?PaymentClient {
                return ++$calls === 1 ? $this->client() : null;
            }
        );
        $this->givenMovements([], []);
        $this->givenNotary();

        $data = $this->service()->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertNotNull($data);
        $this->assertSame('María García López', $data->client->name);
        $this->assertSame('', $data->client->phone);
        $this->assertSame('', $data->client->email);
    }

    public function testInactiveClientIsStillStated(): void
    {
        // Status 2 = inactive (the entity only defines STATUS_ACTIVE); the statement never filters by status
        $inactive = new PaymentClient(self::CLIENT, self::NOTARY, 'María García López', '', '', 2);
        $this->clientRepository->method('findByIdAndNotary')->willReturn($inactive);
        $this->givenMovements([], []);
        $this->givenNotary();

        $this->assertNotNull($this->service()->build(self::CLIENT, self::NOTARY, $this->query()));
    }

    // ---- notary ----------------------------------------------------------

    public function testInactiveNotaryThrowsAndIsLogged(): void
    {
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());
        $this->givenMovements([], []);
        $this->notaryService->method('getByIdActive')->willReturn(null);

        try {
            $this->service()->build(self::CLIENT, self::NOTARY, $this->query());
            $this->fail('Expected StatementGenerationException');
        } catch (StatementGenerationException $e) {
            $this->assertSame('Notary not found', $e->getMessage());
        }

        $this->assertStringContainsString('[statement] notary 1 not found or inactive', $this->logContents());
    }

    public function testNotaryDataFillsTheHeader(): void
    {
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());
        $this->givenMovements([], []);
        $this->givenNotary();

        $data = $this->service()->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertSame('Notaría 42', $data->notary->name);
        $this->assertSame('Lic. Juan Pérez', $data->notary->notaryName);
        $this->assertSame('XAXX010101000', $data->notary->rfc);
        $this->assertSame('Av. Central 100', $data->notary->address);
        $this->assertSame('Querétaro', $data->notary->city);
        $this->assertSame('4421234567', $data->notary->phone);
        $this->assertNull($data->notary->logoDataUri);
    }

    public function testMissingLogoFileIsOmittedAndLogged(): void
    {
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());
        $this->givenMovements([], []);
        $this->givenNotary($this->notary('assets/logo.png'));
        $this->notaryService->method('getLogoInfo')->willReturn(null);

        $data = $this->service()->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertNotNull($data, 'a missing logo never breaks the statement');
        $this->assertNull($data->notary->logoDataUri);
        $this->assertStringContainsString('[pdf] notary 1 has a logo registered but the file does not exist', $this->logContents());
        $this->assertStringNotContainsString('[receipt]', $this->logContents());
    }

    // ---- totals, ledger and legend ---------------------------------------

    public function testTotalsAndBalanceAreTheOnesOfTheStatement(): void
    {
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());
        $this->givenMovements([$this->transaction(1000.0), $this->transaction(200.0, 26, '2026-03-12')], [$this->payment(400.0)]);
        $this->givenNotary();

        $data = $this->service()->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertSame(1200.0, $data->totalCharges);
        $this->assertSame(400.0, $data->totalPayments);
        $this->assertSame(800.0, $data->balance);
        $this->assertSame(0.0, $data->pendingAmount);
        $this->assertTrue($data->hasMovements);
        $this->assertCount(3, $data->rows);
        $this->assertSame(800.0, $data->rows[2]->balance);
        $this->assertSame('', $this->logContents(), 'a consistent ledger logs nothing');
    }

    public function testOpeningBalanceAndPeriodComeFromTheStatement(): void
    {
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());
        $this->givenMovements([$this->transaction(100.0)], []);
        $this->statementRepository->method('sumActiveTransactionsBefore')->willReturn(500.0);
        $this->statementRepository->method('sumPaymentsBefore')->willReturn(200.0);
        $this->givenNotary();

        $data = $this->service()->build(self::CLIENT, self::NOTARY, $this->query(['from' => '2026-03-01', 'to' => '2026-03-31']));

        $this->assertSame('2026-03-01', $data->fromDate);
        $this->assertSame('2026-03-31', $data->toDate);
        $this->assertSame(300.0, $data->pendingAmount);
        $this->assertSame(400.0, $data->balance);
        $this->assertSame(LedgerRowKind::Opening, $data->rows[0]->kind);
        $this->assertSame(300.0, $data->rows[0]->balance);
    }

    public function testNoMovementsStillBuildsTheData(): void
    {
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());
        $this->givenMovements([], []);
        $this->givenNotary();

        $data = $this->service()->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertFalse($data->hasMovements);
        $this->assertSame([], $data->rows);
        $this->assertSame(BalanceStatus::Settled, $data->status);
        $this->assertSame('', $this->logContents(), 'an empty ledger is consistent with a zero balance');
    }

    public function testAmountInWordsForPendingBalance(): void
    {
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());
        $this->givenMovements([$this->transaction(1000.0)], []);
        $this->givenNotary();

        $data = $this->service()->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertSame(BalanceStatus::Pending, $data->status);
        $this->assertSame('MIL PESOS 00/100 M.N.', $data->amountInWords);
    }

    public function testAmountInWordsForBalanceInFavorUsesTheAbsoluteValue(): void
    {
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());
        $this->givenMovements([$this->transaction(100.0)], [$this->payment(400.0)]);
        $this->givenNotary();

        $data = $this->service()->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertSame(BalanceStatus::InFavor, $data->status);
        $this->assertSame(-300.0, $data->balance);
        $this->assertSame('TRESCIENTOS PESOS 00/100 M.N.', $data->amountInWords);
    }

    public function testNoAmountInWordsWhenThereIsNoDebt(): void
    {
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());
        $this->givenMovements([$this->transaction(100.0)], [$this->payment(100.0)]);
        $this->givenNotary();

        $data = $this->service()->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertSame(BalanceStatus::Settled, $data->status);
        $this->assertNull($data->amountInWords);
    }

    // ---- clock -----------------------------------------------------------

    public function testGeneratedAtUsesTheInjectedClockInMexicoCity(): void
    {
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());
        $this->givenMovements([], []);
        $this->givenNotary();

        $data = $this->service()->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertSame('01/04/2024 21:30', $data->generatedAt->format('d/m/Y H:i'));
        $this->assertSame('America/Mexico_City', $data->generatedAt->getTimezone()->getName());
    }

    // ---- consistency check -----------------------------------------------

    public function testInconsistentLedgerIsOnlyReportedAndTheServiceBalanceIsKept(): void
    {
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());
        $this->givenMovements([$this->transaction(1000.0)], []);
        $this->givenNotary();

        $broken = new class () extends StatementLedgerBuilder {
            public function build(ClientStatement $statement): array
            {
                return [new StatementLedgerRow(LedgerRowKind::Charge, '2026-03-10', 'T-000025', 'x', 1.0, null, 999.0)];
            }
        };

        $data = $this->service($broken)->build(self::CLIENT, self::NOTARY, $this->query());

        $this->assertNotNull($data, 'the PDF is still generated');
        $this->assertSame(1000.0, $data->balance, 'the service value is shown, never corrected');
        $this->assertStringContainsString('[statement] client 5: ledger balance 999.00 differs from statement balance 1000.00', $this->logContents());
    }
}
