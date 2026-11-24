<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\Renderer;

use App\Module\PaymentStatement\DTO\StatementLedgerRow;
use App\Module\PaymentStatement\DTO\StatementPdfData;
use App\Module\PaymentStatement\Entity\LedgerRowKind;
use App\Module\Receipt\Service\MoneyFormatter;

/**
 * StatementPdfData to the HTML that Dompdf renders. Values are formatted here (never recalculated) and
 * every one of them goes through ReceiptHtmlBuilder::escape() in the template: names, references and
 * comments are user input.
 */
final class StatementHtmlBuilder
{
    private const TEMPLATE = __DIR__ . '/templates/statement.php';

    public function build(StatementPdfData $data): string
    {
        $view = [
            'data' => $data,
            'period' => $data->periodLabel(),
            'issuedOn' => $data->generatedAt->format('d/m/Y'),
            'rows' => array_map(self::formatRow(...), $data->rows),
            'showOpening' => $data->fromDate !== null,
            'pending' => self::signedMoney($data->pendingAmount),
            'totalCharges' => MoneyFormatter::money($data->totalCharges),
            'totalPayments' => MoneyFormatter::money($data->totalPayments),
            'balance' => self::signedMoney($data->balance),
            'statusLabel' => $data->status->label(),
            // The legend always shows a positive amount: "SALDO A FAVOR" says who owes whom
            'legendAmount' => $data->amountInWords !== null ? MoneyFormatter::money(abs($data->balance)) : null,
        ];

        $render = static function (array $view, string $template): string {
            extract($view, EXTR_SKIP);

            ob_start();
            try {
                require $template;

                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };

        return $render($view, self::TEMPLATE);
    }

    /** @return array{opening: bool, date: string, folio: string, concept: string, charge: string, payment: string, balance: string} */
    private static function formatRow(StatementLedgerRow $row): array
    {
        $opening = $row->kind === LedgerRowKind::Opening;

        return [
            'opening' => $opening,
            // An undated payment (legacy data) shows a dash; the opening row has no date at all
            'date' => $opening ? '' : MoneyFormatter::date($row->date),
            'folio' => $row->folio,
            'concept' => $row->concept,
            'charge' => $row->charge !== null ? MoneyFormatter::money($row->charge) : '',
            'payment' => $row->payment !== null ? MoneyFormatter::money($row->payment) : '',
            'balance' => self::signedMoney($row->balance),
        ];
    }

    /** -$300.00. A negative balance is always marked with its sign, never only with a color. */
    private static function signedMoney(float $amount): string
    {
        return $amount < 0 ? '-' . MoneyFormatter::money(abs($amount)) : MoneyFormatter::money($amount);
    }
}
