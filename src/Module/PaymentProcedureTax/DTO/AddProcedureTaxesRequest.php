<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedureTax\DTO;

final class AddProcedureTaxesRequest
{
    public const MAX_BULK_ITEMS = 50;

    private const TAX_ID_MESSAGE = 'Tax ID must be a positive integer';

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param ProcedureTaxItem[] $items
     */
    private function __construct(
        public readonly bool $isBulk,
        public readonly array $items,
    ) {
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $hasTaxId = array_key_exists('taxId', $data);
        $hasTaxes = array_key_exists('taxes', $data);

        if ($hasTaxId && $hasTaxes) {
            return self::invalid(false, ['body' => 'Provide either taxId or taxes, not both']);
        }

        if (!$hasTaxId && !$hasTaxes) {
            return self::invalid(false, ['body' => 'Either taxId or taxes is required']);
        }

        return $hasTaxes ? self::fromBulk($data['taxes']) : self::fromSingle($data);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function fromSingle(array $data): self
    {
        [$item, $errors] = self::parseItem($data, '');

        if ($errors !== []) {
            return self::invalid(false, $errors);
        }

        return new self(false, [$item]);
    }

    private static function fromBulk(mixed $taxes): self
    {
        if (!is_array($taxes) || $taxes === [] || !array_is_list($taxes)) {
            return self::invalid(true, ['taxes' => 'Taxes must be a non-empty array']);
        }

        if (count($taxes) > self::MAX_BULK_ITEMS) {
            return self::invalid(true, ['taxes' => 'Taxes must not exceed ' . self::MAX_BULK_ITEMS . ' items']);
        }

        $items = [];
        $errors = [];
        $seen = [];

        foreach ($taxes as $index => $raw) {
            // An empty JSON object decodes to [], which is still an object for us.
            if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
                $errors["taxes.$index"] = 'Each tax must be an object';
                continue;
            }

            [$item, $itemErrors] = self::parseItem($raw, "taxes.$index.");
            $errors += $itemErrors;

            if ($item === null) {
                continue;
            }

            if (isset($seen[$item->taxId])) {
                $errors["taxes.$index.taxId"] = 'Duplicate tax ID in request';
                continue;
            }

            $seen[$item->taxId] = true;
            $items[] = $item;
        }

        return $errors === [] ? new self(true, $items) : self::invalid(true, $errors);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: ?ProcedureTaxItem, 1: array<string, string>}
     */
    private static function parseItem(array $data, string $prefix): array
    {
        $errors = [];

        $taxId = $data['taxId'] ?? null;
        if (!is_int($taxId) || $taxId <= 0) {
            $errors[$prefix . 'taxId'] = self::TAX_ID_MESSAGE;
        }

        $value = $data['defaultValue'] ?? null;
        if ($value !== null && ($error = DefaultValueRule::validate($value)) !== null) {
            $errors[$prefix . 'defaultValue'] = $error;
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [new ProcedureTaxItem($taxId, DefaultValueRule::normalize($value)), []];
    }

    /**
     * @param array<string, string> $errors
     */
    private static function invalid(bool $isBulk, array $errors): self
    {
        $dto = new self($isBulk, []);
        $dto->errors = $errors;

        return $dto;
    }
}
