<?php

declare(strict_types=1);

namespace Tests\Module\Receipt\Service;

use App\Core\Database\TransactionManager;
use App\Module\Notary\Entity\Notary;
use App\Module\Notary\Service\NotaryService;
use App\Module\Payment\Entity\Payment;
use App\Module\Payment\Exception\PaymentError;
use App\Module\Payment\Exception\PaymentException;
use App\Module\Payment\Repository\PaymentRepository;
use App\Module\Payment\Service\PaymentService;
use App\Module\PaymentClient\Entity\PaymentClient;
use App\Module\PaymentClient\Repository\PaymentClientRepository;
use App\Module\PaymentClient\Service\PaymentClientService;
use App\Module\PaymentProcedure\Repository\PaymentProcedureRepository;
use App\Module\PaymentProcedureTax\Repository\PaymentProcedureTaxRepository;
use App\Module\PaymentTax\Repository\PaymentTaxRepository;
use App\Module\PaymentTransaction\Entity\PaymentTransaction;
use App\Module\PaymentTransaction\Entity\PaymentTransactionDetail;
use App\Module\PaymentTransaction\Exception\TransactionError;
use App\Module\PaymentTransaction\Exception\TransactionException;
use App\Module\PaymentTransaction\Repository\PaymentTransactionRepository;
use App\Module\PaymentTransaction\Service\PaymentTransactionService;
use App\Module\Receipt\DTO\ReceiptData;
use App\Module\Receipt\Entity\ReceiptType;
use App\Module\Receipt\Exception\ReceiptGenerationException;
use App\Module\Receipt\Service\AmountInWordsConverter;
use App\Module\Receipt\Service\ReceiptService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The payment/transaction/client Services are final, so they are built for real over mocked
 * repositories (the same approach the rest of the suite uses for their own Services).
 */
final class ReceiptServiceTest extends TestCase
{
    private const NOTARY = 1;
    private const CLIENT = 5;
    private const UUID = 'Us2Gvq';

    private PaymentTransactionRepository&MockObject $transactionRepository;
    private PaymentRepository&MockObject $paymentRepository;
    private PaymentClientRepository&MockObject $clientRepository;
    private NotaryService&MockObject $notaryService;
    private ReceiptService $service;

    private string|false $previousErrorLog = false;
    private ?string $logFile = null;

    protected function setUp(): void
    {
        $this->transactionRepository = $this->createMock(PaymentTransactionRepository::class);
        $this->paymentRepository = $this->createMock(PaymentRepository::class);
        $this->clientRepository = $this->createMock(PaymentClientRepository::class);
        $this->notaryService = $this->createMock(NotaryService::class);

        $this->service = new ReceiptService(
            new PaymentTransactionService(
                $this->transactionRepository,
                $this->clientRepository,
                $this->createMock(PaymentProcedureRepository::class),
                $this->createMock(PaymentTaxRepository::class),
                $this->createMock(PaymentProcedureTaxRepository::class),
                $this->createMock(TransactionManager::class),
            ),
            new PaymentService($this->paymentRepository, $this->clientRepository),
            new PaymentClientService($this->clientRepository),
            $this->notaryService,
            new AmountInWordsConverter(),
            // 2024-04-02 03:30 UTC is still 01/04 21:30 in Mexico City
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2024-04-02 03:30:00', new \DateTimeZone('UTC')),
        );

        // error_log() goes to a file so tests can assert on what was reported
        $this->logFile = tempnam(sys_get_temp_dir(), 'receipt-log');
        $this->previousErrorLog = ini_set('error_log', (string) $this->logFile);
        $this->notaryService->method('getByIdActive')->willReturn($this->notary());
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

    private function notary(string $logo = ''): Notary
    {
        return new Notary(self::NOTARY, 'Notaría 42', 'Lic. Juan Pérez', 'Querétaro', 'Av. Central 100', '4421234567', 'XAXX010101000', $logo, 1);
    }

    private function client(): PaymentClient
    {
        return new PaymentClient(self::CLIENT, self::NOTARY, 'Iñaki Ángeles', '4429876543', 'inaki@example.com', 1);
    }

    /** @param PaymentTransactionDetail[] $details */
    private function transaction(
        int $statusId = 1,
        float $fee = 1000.0,
        float $taxes = 350.0,
        float $total = 1350.0,
        array $details = [],
        string $clientName = 'Iñaki Ángeles',
    ): PaymentTransaction {
        return new PaymentTransaction(
            19, self::UUID, self::NOTARY, self::CLIENT, $clientName, 3, 'Compraventa', '2024-04-02',
            $fee, $taxes, $total, 'REF-1', 'Ana López', 'Nota interna', $statusId, $details,
        );
    }

    private function payment(?string $date = '2024-04-02', string $comments = 'Adelanto', float $amount = 5000.5): Payment
    {
        return new Payment(7, self::UUID, self::NOTARY, self::CLIENT, 'Iñaki Ángeles', $amount, $date, $comments);
    }

    private function logContents(): string
    {
        return (string) file_get_contents((string) $this->logFile);
    }

    // ---- transactions ----------------------------------------------------

    public function testBuildsReceiptForActiveTransactionById(): void
    {
        $details = [new PaymentTransactionDetail(1, 'ISAI', 2, 175.0, '2024-04-02', '')];
        $this->transactionRepository->expects($this->once())->method('findByIdAndNotary')->with(19, self::NOTARY)
            ->willReturn($this->transaction(details: $details));
        $this->clientRepository->method('findByIdAndNotary')->with(self::CLIENT, self::NOTARY)->willReturn($this->client());

        $data = $this->service->buildForTransactionId(19, self::NOTARY);

        $this->assertSame(ReceiptType::Transaction, $data->type);
        $this->assertSame('T-000019', $data->folio());
        $this->assertSame('recibo-tramite-T-000019.pdf', $data->fileName());
        $this->assertSame(self::UUID, $data->uuid);
        $this->assertSame('2024-04-02', $data->date);
        $this->assertSame('Iñaki Ángeles', $data->client->name);
        $this->assertSame('4429876543', $data->client->phone);
        $this->assertSame('inaki@example.com', $data->client->email);
        $this->assertSame('Compraventa', $data->procedureName);
        $this->assertSame('REF-1', $data->reference);
        $this->assertSame('Ana López', $data->responsible);
        $this->assertSame(1000.0, $data->fee);
        $this->assertSame(350.0, $data->taxes);
        $this->assertSame(1350.0, $data->total);
        $this->assertSame('MIL TRESCIENTOS CINCUENTA PESOS 00/100 M.N.', $data->amountInWords);
        $this->assertFalse($data->cancelled);
        $this->assertSame('Notaría 42', $data->notary->name);
        $this->assertNull($data->notary->logoDataUri);
        $this->assertSame('', $this->logContents());
    }

    public function testTaxLineShowsLineTotalNotUnitAmount(): void
    {
        $details = [new PaymentTransactionDetail(1, 'ISAI', 2, 175.0, null, '')];
        $this->transactionRepository->method('findByIdAndNotary')->willReturn($this->transaction(details: $details));
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());

        $line = $this->service->buildForTransactionId(19, self::NOTARY)->taxLines[0];

        $this->assertSame(2, $line->quantity);
        $this->assertSame('ISAI', $line->concept);
        $this->assertSame(350.0, $line->amount);
        $this->assertSame('', $this->logContents(), 'details add up to the taxes: nothing to report');
    }

    public function testBuildsReceiptByUuid(): void
    {
        $this->transactionRepository->expects($this->once())->method('findByUuidAndNotary')->with(self::UUID, self::NOTARY)
            ->willReturn($this->transaction());
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());

        $data = $this->service->buildForTransactionUuid(self::UUID, self::NOTARY);

        $this->assertSame(self::UUID, $data->uuid);
    }

    public function testInactiveTransactionIsMarkedCancelled(): void
    {
        $this->transactionRepository->method('findByIdAndNotary')->willReturn($this->transaction(statusId: 2));
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());

        $this->assertTrue($this->service->buildForTransactionId(19, self::NOTARY)->cancelled);
    }

    public function testMissingTransactionThrowsNotFound(): void
    {
        $this->transactionRepository->method('findByIdAndNotary')->with(19, self::NOTARY)->willReturn(null);

        try {
            $this->service->buildForTransactionId(19, self::NOTARY);
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame(TransactionError::TransactionNotFound, $e->error);
        }
    }

    public function testDeletedClientDoesNotBreakTheReceipt(): void
    {
        $this->transactionRepository->method('findByIdAndNotary')->willReturn($this->transaction(clientName: ''));
        $this->clientRepository->method('findByIdAndNotary')->willReturn(null);

        $data = $this->service->buildForTransactionId(19, self::NOTARY);

        $this->assertSame('', $data->client->name);
        $this->assertSame('', $data->client->phone);
        $this->assertSame('', $data->client->email);
    }

    public function testInconsistentTotalIsShownAsStoredAndReported(): void
    {
        $this->transactionRepository->method('findByIdAndNotary')
            ->willReturn($this->transaction(fee: 1000.0, taxes: 350.0, total: 1400.0));
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());

        $data = $this->service->buildForTransactionId(19, self::NOTARY);

        $this->assertSame(1400.0, $data->total, 'stored value is shown, never recalculated');
        $this->assertStringContainsString('transaction 19', $this->logContents());
        $this->assertStringContainsString('differs from fee + taxes', $this->logContents());
    }

    public function testTaxesNotMatchingDetailsAreReported(): void
    {
        $details = [new PaymentTransactionDetail(1, 'ISAI', 1, 100.0, null, '')];
        $this->transactionRepository->method('findByIdAndNotary')->willReturn($this->transaction(details: $details));
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());

        $this->service->buildForTransactionId(19, self::NOTARY);

        $this->assertStringContainsString('differ from the sum of its details', $this->logContents());
    }

    public function testManualTaxesWithoutDetailsAreNotReported(): void
    {
        $this->transactionRepository->method('findByIdAndNotary')->willReturn($this->transaction(details: []));
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());

        $data = $this->service->buildForTransactionId(19, self::NOTARY);

        $this->assertSame([], $data->taxLines);
        $this->assertSame('', $this->logContents());
    }

    // ---- payments --------------------------------------------------------

    public function testBuildsReceiptForPaymentById(): void
    {
        $this->paymentRepository->expects($this->once())->method('findByIdAndNotary')->with(7, self::NOTARY)
            ->willReturn($this->payment());
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());

        $data = $this->service->buildForPaymentId(7, self::NOTARY);

        $this->assertSame(ReceiptType::Payment, $data->type);
        $this->assertSame('C-000007', $data->folio());
        $this->assertSame('recibo-cobro-C-000007.pdf', $data->fileName());
        $this->assertSame('2024-04-02', $data->date);
        $this->assertSame(5000.5, $data->total);
        $this->assertSame('CINCO MIL PESOS 50/100 M.N.', $data->amountInWords);
        $this->assertSame('Adelanto', $data->comments);
        $this->assertFalse($data->cancelled, 'payments have no status and are never cancelled');
        $this->assertSame([], $data->taxLines);
    }

    public function testPaymentWithoutDateAndCommentsKeepsEmptyValues(): void
    {
        $this->paymentRepository->method('findByUuidAndNotary')->with(self::UUID, self::NOTARY)
            ->willReturn($this->payment(date: null, comments: ''));
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());

        $data = $this->service->buildForPaymentUuid(self::UUID, self::NOTARY);

        $this->assertNull($data->date);
        $this->assertSame('', $data->comments);
    }

    public function testMissingPaymentThrowsNotFound(): void
    {
        $this->paymentRepository->method('findByIdAndNotary')->willReturn(null);

        try {
            $this->service->buildForPaymentId(7, self::NOTARY);
            $this->fail('Expected PaymentException');
        } catch (PaymentException $e) {
            $this->assertSame(PaymentError::PaymentNotFound, $e->error);
        }
    }

    // ---- notary, logo, clock ---------------------------------------------

    public function testQueriesAreScopedToTheNotaryOfTheCaller(): void
    {
        $this->paymentRepository->expects($this->once())->method('findByIdAndNotary')->with(7, 99)->willReturn(null);

        $this->expectException(PaymentException::class);

        $this->service->buildForPaymentId(7, 99);
    }

    public function testGeneratedAtUsesMexicoCityTimezone(): void
    {
        $this->paymentRepository->method('findByIdAndNotary')->willReturn($this->payment());
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());

        $generatedAt = $this->service->buildForPaymentId(7, self::NOTARY)->generatedAt;

        $this->assertSame('America/Mexico_City', $generatedAt->getTimezone()->getName());
        $this->assertSame('01/04/2024 21:30', $generatedAt->format('d/m/Y H:i'));
    }

    public function testLogoIsEmbeddedAsDataUri(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($file, 'PNGDATA');

        $notaryService = $this->createMock(NotaryService::class);
        $notaryService->method('getByIdActive')->willReturn($this->notary('assets/1/logo/logo_1.png'));
        $notaryService->method('getLogoInfo')->with(self::NOTARY)
            ->willReturn(['path' => $file, 'mimeType' => 'image/png', 'size' => 7]);

        try {
            $data = $this->buildPaymentWith($notaryService);
        } finally {
            unlink($file);
        }

        $this->assertSame('data:image/png;base64,' . base64_encode('PNGDATA'), $data->notary->logoDataUri);
    }

    public function testMissingLogoFileIsOmittedAndReported(): void
    {
        $notaryService = $this->createMock(NotaryService::class);
        $notaryService->method('getByIdActive')->willReturn($this->notary('assets/1/logo/logo_1.png'));
        $notaryService->method('getLogoInfo')->willReturn(null);

        $data = $this->buildPaymentWith($notaryService);

        $this->assertNull($data->notary->logoDataUri);
        $this->assertStringContainsString('logo registered but the file does not exist', $this->logContents());
    }

    public function testUnsupportedLogoTypeIsOmittedAndReported(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($file, '<svg onload="x"/>');

        $notaryService = $this->createMock(NotaryService::class);
        $notaryService->method('getByIdActive')->willReturn($this->notary('assets/1/logo/logo_1.svg'));
        $notaryService->method('getLogoInfo')->willReturn(['path' => $file, 'mimeType' => 'image/svg+xml', 'size' => 17]);

        try {
            $data = $this->buildPaymentWith($notaryService);
        } finally {
            unlink($file);
        }

        $this->assertNull($data->notary->logoDataUri);
        $this->assertStringContainsString('unsupported type', $this->logContents());
    }

    public function testMissingNotaryFailsGeneration(): void
    {
        $notaryService = $this->createMock(NotaryService::class);
        $notaryService->method('getByIdActive')->willReturn(null);

        $this->expectException(ReceiptGenerationException::class);

        $this->buildPaymentWith($notaryService);
    }

    private function buildPaymentWith(NotaryService $notaryService): ReceiptData
    {
        $this->paymentRepository->method('findByIdAndNotary')->willReturn($this->payment());
        $this->clientRepository->method('findByIdAndNotary')->willReturn($this->client());

        $service = new ReceiptService(
            new PaymentTransactionService(
                $this->transactionRepository,
                $this->clientRepository,
                $this->createMock(PaymentProcedureRepository::class),
                $this->createMock(PaymentTaxRepository::class),
                $this->createMock(PaymentProcedureTaxRepository::class),
                $this->createMock(TransactionManager::class),
            ),
            new PaymentService($this->paymentRepository, $this->clientRepository),
            new PaymentClientService($this->clientRepository),
            $notaryService,
            new AmountInWordsConverter(),
        );

        return $service->buildForPaymentId(7, self::NOTARY);
    }
}
