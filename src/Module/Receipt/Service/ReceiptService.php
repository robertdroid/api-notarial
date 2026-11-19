<?php

declare(strict_types=1);

namespace App\Module\Receipt\Service;

use App\Module\Notary\Entity\Notary;
use App\Module\Notary\Service\NotaryService;
use App\Module\Payment\Entity\Payment;
use App\Module\Payment\Service\PaymentService;
use App\Module\PaymentClient\Entity\PaymentClient;
use App\Module\PaymentClient\Service\PaymentClientService;
use App\Module\PaymentTransaction\Entity\PaymentTransaction;
use App\Module\PaymentTransaction\Entity\PaymentTransactionDetail;
use App\Module\PaymentTransaction\Service\PaymentTransactionService;
use App\Module\Receipt\DTO\ReceiptClient;
use App\Module\Receipt\DTO\ReceiptData;
use App\Module\Receipt\DTO\ReceiptNotary;
use App\Module\Receipt\DTO\ReceiptTaxLine;
use App\Module\Receipt\Entity\ReceiptType;
use App\Module\Receipt\Exception\ReceiptGenerationException;

/**
 * Builds the data of a receipt. No HTML and no PDF here.
 *
 * Every lookup goes through the existing Services, which already filter by notary_id and
 * throw TransactionException / PaymentException (404) for missing or foreign records.
 */
final class ReceiptService
{
    private const TIMEZONE = 'America/Mexico_City';
    private const LOGO_MIME_TYPES = ['image/png', 'image/jpeg'];
    private const TOLERANCE = 0.005;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param \Closure(): \DateTimeImmutable|null $clock Injectable so generatedAt is testable */
    public function __construct(
        private readonly PaymentTransactionService $transactionService,
        private readonly PaymentService $paymentService,
        private readonly PaymentClientService $clientService,
        private readonly NotaryService $notaryService,
        private readonly AmountInWordsConverter $amountInWords,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now');
    }

    public function buildForTransactionId(int $id, int $notaryId): ReceiptData
    {
        return $this->fromTransaction($this->transactionService->getById($id, $notaryId), $notaryId);
    }

    public function buildForTransactionUuid(string $uuid, int $notaryId): ReceiptData
    {
        return $this->fromTransaction($this->transactionService->getByUuid($uuid, $notaryId), $notaryId);
    }

    public function buildForPaymentId(int $id, int $notaryId): ReceiptData
    {
        return $this->fromPayment($this->paymentService->getById($id, $notaryId), $notaryId);
    }

    public function buildForPaymentUuid(string $uuid, int $notaryId): ReceiptData
    {
        return $this->fromPayment($this->paymentService->getByUuid($uuid, $notaryId), $notaryId);
    }

    private function fromTransaction(PaymentTransaction $tx, int $notaryId): ReceiptData
    {
        $client = $this->clientService->getById($tx->clientId, $notaryId);
        $notary = $this->buildNotary($notaryId);

        $this->warnIfInconsistent($tx);

        return new ReceiptData(
            type: ReceiptType::Transaction,
            id: $tx->id,
            uuid: $tx->uuid,
            date: $tx->date,
            notary: $notary,
            client: $this->buildClient($client, $tx->clientName),
            total: $tx->total,
            amountInWords: $this->amountInWords->convert($tx->total),
            cancelled: $tx->statusId !== PaymentTransaction::STATUS_ACTIVE,
            generatedAt: $this->now(),
            procedureName: $tx->procedureName,
            reference: $tx->reference,
            responsible: $tx->responsible,
            fee: $tx->fee,
            taxes: $tx->taxes,
            taxLines: array_map(
                static fn (PaymentTransactionDetail $d): ReceiptTaxLine => new ReceiptTaxLine(
                    $d->quantity,
                    $d->taxName,
                    // The stored amount is per unit; the receipt shows the line total so the column adds up to the taxes
                    round($d->quantity * $d->amount, 2),
                ),
                $tx->details
            ),
            comments: $tx->comments,
        );
    }

    private function fromPayment(Payment $payment, int $notaryId): ReceiptData
    {
        $client = $this->clientService->getById($payment->clientId, $notaryId);
        $notary = $this->buildNotary($notaryId);

        return new ReceiptData(
            type: ReceiptType::Payment,
            id: $payment->id,
            uuid: $payment->uuid,
            date: $payment->paymentDate,
            notary: $notary,
            client: $this->buildClient($client, $payment->clientName),
            total: $payment->amount,
            amountInWords: $this->amountInWords->convert($payment->amount),
            // Payments have no status: they are never cancelled
            cancelled: false,
            generatedAt: $this->now(),
            comments: $payment->comments,
        );
    }

    /** A deleted/unknown client does not break the receipt: fields stay empty and render as a dash. */
    private function buildClient(?PaymentClient $client, string $joinedName): ReceiptClient
    {
        return new ReceiptClient(
            name: $client->fullName ?? $joinedName,
            phone: $client->phone ?? '',
            email: $client->email ?? '',
        );
    }

    private function buildNotary(int $notaryId): ReceiptNotary
    {
        $notary = $this->notaryService->getByIdActive($notaryId);
        if ($notary === null) {
            error_log(sprintf('[receipt] notary %d not found or inactive', $notaryId));

            throw new ReceiptGenerationException('Notary not found');
        }

        return new ReceiptNotary(
            name: $notary->name,
            notaryName: $notary->notaryName,
            rfc: $notary->rfc,
            address: $notary->address,
            city: $notary->city,
            phone: $notary->phone,
            logoDataUri: $this->logoDataUri($notary),
        );
    }

    /** A missing or unreadable logo is omitted (with a log line); it never breaks the receipt. */
    private function logoDataUri(Notary $notary): ?string
    {
        if (!$notary->hasLogo()) {
            return null;
        }

        $info = $this->notaryService->getLogoInfo($notary->id);
        if ($info === null) {
            error_log(sprintf('[receipt] notary %d has a logo registered but the file does not exist', $notary->id));

            return null;
        }

        $mime = (string) ($info['mimeType'] ?? '');
        $path = (string) ($info['path'] ?? '');
        $bytes = in_array($mime, self::LOGO_MIME_TYPES, true) ? @file_get_contents($path) : false;

        if ($bytes === false || $bytes === '') {
            error_log(sprintf('[receipt] notary %d logo is unreadable or has an unsupported type (%s)', $notary->id, $mime));

            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * Stored values are shown as they are (never recalculated); a mismatch is only reported
     * so someone can look into the data.
     */
    private function warnIfInconsistent(PaymentTransaction $tx): void
    {
        if (abs($tx->total - ($tx->fee + $tx->taxes)) > self::TOLERANCE) {
            error_log(sprintf('[receipt] transaction %d: total %.2f differs from fee + taxes', $tx->id, $tx->total));
        }

        // Manual mode (no details) stores taxes without a breakdown, so only compare when there are details
        if ($tx->details !== []) {
            $detailsSum = array_sum(array_map(
                static fn (PaymentTransactionDetail $d): float => $d->quantity * $d->amount,
                $tx->details
            ));

            if (abs($tx->taxes - $detailsSum) > self::TOLERANCE) {
                error_log(sprintf('[receipt] transaction %d: taxes %.2f differ from the sum of its details', $tx->id, $tx->taxes));
            }
        }
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock)()->setTimezone(new \DateTimeZone(self::TIMEZONE));
    }
}
