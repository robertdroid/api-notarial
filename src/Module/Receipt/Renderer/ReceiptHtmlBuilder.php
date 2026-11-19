<?php

declare(strict_types=1);

namespace App\Module\Receipt\Renderer;

use App\Module\Receipt\DTO\ReceiptData;
use App\Module\Receipt\Entity\ReceiptType;
use App\Module\Receipt\Service\MoneyFormatter;

/**
 * ReceiptData to the HTML that Dompdf renders. Every value that reaches the template goes through
 * escape(): names, comments and notary data are user input.
 */
final class ReceiptHtmlBuilder
{
    private const TEMPLATE = __DIR__ . '/templates/receipt.php';

    public function build(ReceiptData $data, string $qrDataUri): string
    {
        $isTransaction = $data->type === ReceiptType::Transaction;
        $view = [
            'title' => $data->type->title(),
            'folio' => $data->folio(),
            'date' => MoneyFormatter::date($data->date, $isTransaction ? '—' : 'Sin fecha'),
            'isTransaction' => $isTransaction,
            'cancelled' => $data->cancelled,
            'qrDataUri' => $qrDataUri,
            'data' => $data,
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

    public static function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Escaped value, or a dash when it is empty. */
    public static function orDash(?string $value): string
    {
        return $value === null || trim($value) === '' ? '—' : self::escape($value);
    }
}
