<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\DTO;

/**
 * Validates the fields shared by the POST and PUT bodies.
 */
final class TransactionBodyParser
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     * @return array{
     *     procedureId: int, date: string, fee: float, taxes: float,
     *     reference: string, responsible: string, comments: string, details: TransactionDetailItem[]
     * }
     */
    public static function parse(array $data, array &$errors): array
    {
        $procedureId = 0;
        if (!array_key_exists('procedureId', $data)) {
            $errors['procedureId'] = 'Procedure ID is required';
        } elseif (!TransactionFieldRules::positiveInt($data['procedureId'])) {
            $errors['procedureId'] = 'Procedure ID must be a positive integer';
        } else {
            $procedureId = $data['procedureId'];
        }

        $date = '';
        if (!array_key_exists('date', $data)) {
            $errors['date'] = 'Date is required';
        } elseif (!TransactionFieldRules::date($data['date'])) {
            $errors['date'] = 'Date must be a valid date in YYYY-MM-DD format';
        } else {
            $date = $data['date'];
        }

        return [
            'procedureId' => $procedureId,
            'date' => $date,
            'fee' => self::optionalMoney($data, 'fee', 'Fee', $errors),
            'taxes' => self::optionalMoney($data, 'taxes', 'Taxes', $errors),
            'reference' => self::optionalText($data, 'reference', 'Reference', $errors),
            'responsible' => self::optionalText($data, 'responsible', 'Responsible', $errors),
            'comments' => self::optionalText($data, 'comments', 'Comments', $errors),
            'details' => TransactionDetailsParser::parse($data['details'] ?? null, $errors),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    private static function optionalMoney(array $data, string $key, string $label, array &$errors): float
    {
        if (!array_key_exists($key, $data)) {
            return 0.0;
        }

        $error = TransactionFieldRules::money($data[$key], $label);
        if ($error !== null) {
            $errors[$key] = $error;

            return 0.0;
        }

        return round((float) $data[$key], 2);
    }

    /**
     * null is accepted and means "empty".
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    private static function optionalText(array $data, string $key, string $label, array &$errors): string
    {
        if (($data[$key] ?? null) === null) {
            return '';
        }

        $error = TransactionFieldRules::text($data[$key], $label);
        if ($error !== null) {
            $errors[$key] = $error;

            return '';
        }

        return trim($data[$key]);
    }
}
