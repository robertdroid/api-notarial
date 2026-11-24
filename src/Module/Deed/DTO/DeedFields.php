<?php

declare(strict_types=1);

namespace App\Module\Deed\DTO;

/**
 * Editable fields of a deed, shared by create and update.
 */
final class DeedFields
{
    public function __construct(
        public readonly string $deedNumber,
        public readonly string $book,
        public readonly string $deedDate,
        public readonly int $actId,
        public readonly int $deedTypeId,
        public readonly ?int $subdivisionId,
        public readonly ?string $property,
        public readonly ?string $deliveryDate,
        public readonly ?string $deliveredBy,
        public readonly ?string $observations,
    ) {
    }

    /**
     * Parses and validates the editable fields. Unknown keys are ignored.
     * On errors the returned instance holds placeholders and must not be used.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    public static function parse(array $data, array &$errors): self
    {
        return new self(
            deedNumber: self::requiredString($data, 'deedNumber', 'Deed number', DeedFieldRules::MAX_DEED_NUMBER, $errors),
            book: self::requiredString($data, 'book', 'Book', DeedFieldRules::MAX_BOOK, $errors),
            deedDate: self::requiredDate($data, $errors),
            actId: self::requiredId($data, 'actId', 'Act ID', $errors),
            deedTypeId: self::requiredId($data, 'deedTypeId', 'Deed type ID', $errors),
            subdivisionId: self::optionalId($data, 'subdivisionId', 'Subdivision ID', $errors),
            property: self::optionalText($data, 'property', 'Property', $errors),
            deliveryDate: self::optionalDate($data, 'deliveryDate', 'Delivery date', $errors),
            deliveredBy: self::optionalString($data, 'deliveredBy', 'Delivered by', DeedFieldRules::MAX_DELIVERED_BY, $errors),
            observations: self::optionalText($data, 'observations', 'Observations', $errors),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    private static function requiredString(array $data, string $key, string $label, int $max, array &$errors): string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            $errors[$key] = "$label is required";

            return '';
        }

        if (($error = DeedFieldRules::string($data[$key], $max, $label)) !== null) {
            $errors[$key] = $error;

            return '';
        }

        $value = trim($data[$key]);
        if ($value === '') {
            $errors[$key] = "$label is required";
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    private static function requiredDate(array $data, array &$errors): string
    {
        if (!array_key_exists('deedDate', $data) || $data['deedDate'] === null) {
            $errors['deedDate'] = 'Deed date is required';

            return '';
        }

        if (!DeedFieldRules::date($data['deedDate'])) {
            $errors['deedDate'] = 'Deed date must be a valid date in YYYY-MM-DD format';

            return '';
        }

        return $data['deedDate'];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    private static function requiredId(array $data, string $key, string $label, array &$errors): int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            $errors[$key] = "$label is required";

            return 0;
        }

        if (!DeedFieldRules::positiveInt($data[$key])) {
            $errors[$key] = "$label must be a positive integer";

            return 0;
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    private static function optionalId(array $data, string $key, string $label, array &$errors): ?int
    {
        if (($data[$key] ?? null) === null) {
            return null;
        }

        if (!DeedFieldRules::positiveInt($data[$key])) {
            $errors[$key] = "$label must be a positive integer";

            return null;
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    private static function optionalString(array $data, string $key, string $label, int $max, array &$errors): ?string
    {
        if (($data[$key] ?? null) === null) {
            return null;
        }

        if (($error = DeedFieldRules::string($data[$key], $max, $label)) !== null) {
            $errors[$key] = $error;

            return null;
        }

        return DeedFieldRules::normalizeOptional($data[$key]);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    private static function optionalText(array $data, string $key, string $label, array &$errors): ?string
    {
        if (($data[$key] ?? null) === null) {
            return null;
        }

        if (($error = DeedFieldRules::text($data[$key], $label)) !== null) {
            $errors[$key] = $error;

            return null;
        }

        return DeedFieldRules::normalizeOptional($data[$key]);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    private static function optionalDate(array $data, string $key, string $label, array &$errors): ?string
    {
        if (($data[$key] ?? null) === null) {
            return null;
        }

        if (!DeedFieldRules::date($data[$key])) {
            $errors[$key] = "$label must be a valid date in YYYY-MM-DD format";

            return null;
        }

        return $data[$key];
    }
}
