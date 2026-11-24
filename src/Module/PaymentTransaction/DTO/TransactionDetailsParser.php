<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\DTO;

final class TransactionDetailsParser
{
    /**
     * Repeated taxIds are NOT an error here: the Service keeps the first one.
     *
     * @param array<string, string> $errors
     * @return TransactionDetailItem[]
     */
    public static function parse(mixed $details, array &$errors): array
    {
        if ($details === null) {
            return [];
        }

        if (!is_array($details) || !array_is_list($details)) {
            $errors['details'] = 'Details must be an array';

            return [];
        }

        if (count($details) > TransactionFieldRules::MAX_DETAILS) {
            $errors['details'] = 'Details must not exceed ' . TransactionFieldRules::MAX_DETAILS . ' items';

            return [];
        }

        $items = [];
        foreach ($details as $index => $detail) {
            $item = self::parseItem($detail, "details.$index", $errors);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** @param array<string, string> $errors */
    private static function parseItem(mixed $detail, string $key, array &$errors): ?TransactionDetailItem
    {
        if (!is_array($detail) || ($detail !== [] && array_is_list($detail))) {
            $errors[$key] = 'Each detail must be an object';

            return null;
        }

        $valid = true;

        $taxId = $detail['taxId'] ?? null;
        if (!TransactionFieldRules::positiveInt($taxId)) {
            $errors["$key.taxId"] = 'Tax ID must be a positive integer';
            $valid = false;
        }

        $quantity = 1;
        if (array_key_exists('quantity', $detail)) {
            $error = TransactionFieldRules::quantity($detail['quantity']);
            if ($error !== null) {
                $errors["$key.quantity"] = $error;
                $valid = false;
            } else {
                $quantity = $detail['quantity'];
            }
        }

        $amount = 0.0;
        if (array_key_exists('amount', $detail)) {
            $error = TransactionFieldRules::money($detail['amount'], 'Detail amount');
            if ($error !== null) {
                $errors["$key.amount"] = $error;
                $valid = false;
            } else {
                $amount = round((float) $detail['amount'], 2);
            }
        }

        $date = $detail['date'] ?? null;
        if ($date !== null && !TransactionFieldRules::date($date)) {
            $errors["$key.date"] = 'Detail date must be a valid date in YYYY-MM-DD format';
            $valid = false;
        }

        $comments = '';
        if (($detail['comments'] ?? null) !== null) {
            $error = TransactionFieldRules::text($detail['comments'], 'Detail comments');
            if ($error !== null) {
                $errors["$key.comments"] = $error;
                $valid = false;
            } else {
                $comments = trim($detail['comments']);
            }
        }

        return $valid ? new TransactionDetailItem($taxId, $quantity, $amount, $date, $comments) : null;
    }
}
