<?php

declare(strict_types=1);

namespace App\Module\PaymentTax\DTO;

final class UpdatePaymentTaxRequest
{
    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(
        public readonly ?string $taxName,
        public readonly ?int $status,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $taxName = null;
        $status = null;

        $hasName = array_key_exists('taxName', $data);
        $hasStatus = array_key_exists('status', $data);

        if (!$hasName && !$hasStatus) {
            $errors['body'] = 'At least one field (taxName, status) is required';
        }

        if ($hasName) {
            $error = TaxNameRule::validate($data['taxName']);
            if ($error !== null) {
                $errors['taxName'] = $error;
            } else {
                $taxName = trim($data['taxName']);
            }
        }

        if ($hasStatus) {
            if (!is_int($data['status']) || !in_array($data['status'], [1, 2], true)) {
                $errors['status'] = 'Status must be 1 or 2';
            } else {
                $status = $data['status'];
            }
        }

        $instance = new self($taxName, $status);
        $instance->errors = $errors;

        return $instance;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
