<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\Service;

use App\Module\Notary\Service\NotaryService;
use App\Module\PaymentClient\Repository\PaymentClientRepository;
use App\Module\PaymentStatement\DTO\StatementPdfData;
use App\Module\PaymentStatement\DTO\StatementQuery;
use App\Module\PaymentStatement\Entity\BalanceStatus;
use App\Module\PaymentStatement\Entity\ClientStatement;
use App\Module\PaymentStatement\Exception\StatementGenerationException;
use App\Module\Receipt\DTO\ReceiptClient;
use App\Module\Receipt\DTO\ReceiptNotary;
use App\Module\Receipt\Service\AmountInWordsConverter;
use App\Shared\Pdf\LogoDataUriReader;

/**
 * Builds the data of the statement PDF. No HTML and no PDF here.
 *
 * Movements and totals come only from PaymentStatementService, in the same process, so the PDF shows
 * exactly the numbers of the JSON statement. Read-only: no writes and no transactions.
 */
final class StatementPdfService
{
    private const TIMEZONE = 'America/Mexico_City';
    private const TOLERANCE = 0.005;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param \Closure(): \DateTimeImmutable|null $clock Injectable so generatedAt is testable */
    public function __construct(
        private readonly PaymentStatementService $statementService,
        private readonly PaymentClientRepository $clientRepository,
        private readonly NotaryService $notaryService,
        private readonly LogoDataUriReader $logoReader,
        private readonly StatementLedgerBuilder $ledgerBuilder,
        private readonly AmountInWordsConverter $amountInWords,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now');
    }

    /**
     * Returns null when the client does not exist in the notary (the controller answers 404).
     * In that case nothing else is queried: a foreign client is indistinguishable from a missing one.
     *
     * @throws StatementGenerationException When the notary of the token is missing or inactive
     */
    public function build(int $clientId, int $notaryId, StatementQuery $query): ?StatementPdfData
    {
        $statement = $this->statementService->build($clientId, $notaryId, $query);
        if ($statement === null) {
            return null;
        }

        $notary = $this->buildNotary($notaryId);
        $rows = $this->ledgerBuilder->build($statement);
        $status = BalanceStatus::fromBalance($statement->balance);

        $this->warnIfInconsistent($statement, $rows === [] ? $statement->pendingAmount : $rows[array_key_last($rows)]->balance);

        return new StatementPdfData(
            clientId: $statement->clientId,
            notary: $notary,
            client: $this->buildClient($statement, $notaryId),
            fromDate: $statement->fromDate,
            toDate: $statement->toDate,
            rows: $rows,
            hasMovements: $statement->transactions !== [] || $statement->payments !== [],
            pendingAmount: $statement->pendingAmount,
            totalCharges: $statement->totalCharges,
            totalPayments: $statement->totalPayments,
            balance: $statement->balance,
            status: $status,
            amountInWords: $this->amountInWords($status, $statement->balance),
            generatedAt: ($this->clock)()->setTimezone(new \DateTimeZone(self::TIMEZONE)),
        );
    }

    /**
     * Phone and email are not part of the statement: one extra read by primary key (ADR-002). If the client
     * disappeared between both reads, the statement name is used and the rest renders as a dash.
     */
    private function buildClient(ClientStatement $statement, int $notaryId): ReceiptClient
    {
        $client = $this->clientRepository->findByIdAndNotary($statement->clientId, $notaryId);

        return new ReceiptClient(
            name: $client->fullName ?? $statement->clientName,
            phone: $client->phone ?? '',
            email: $client->email ?? '',
        );
    }

    private function buildNotary(int $notaryId): ReceiptNotary
    {
        $notary = $this->notaryService->getByIdActive($notaryId);
        if ($notary === null) {
            error_log(sprintf('[statement] notary %d not found or inactive', $notaryId));

            throw new StatementGenerationException('Notary not found');
        }

        return new ReceiptNotary(
            name: $notary->name,
            notaryName: $notary->notaryName,
            rfc: $notary->rfc,
            address: $notary->address,
            city: $notary->city,
            phone: $notary->phone,
            logoDataUri: $this->logoReader->read($notary),
        );
    }

    /** A balance in favor is spelled out as a positive amount; no debt has no amount in words. */
    private function amountInWords(BalanceStatus $status, float $balance): ?string
    {
        return match ($status) {
            BalanceStatus::Pending => $this->amountInWords->convert($balance),
            BalanceStatus::InFavor => $this->amountInWords->convert(abs($balance)),
            BalanceStatus::Settled => null,
        };
    }

    /** The service value is always shown as it is; a mismatch is only reported so someone can look into the data. */
    private function warnIfInconsistent(ClientStatement $statement, float $ledgerBalance): void
    {
        if (abs($ledgerBalance - $statement->balance) > self::TOLERANCE) {
            error_log(sprintf(
                '[statement] client %d: ledger balance %.2f differs from statement balance %.2f',
                $statement->clientId,
                $ledgerBalance,
                $statement->balance
            ));
        }
    }
}
