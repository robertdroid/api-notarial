<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedureTax\DTO;

final class UpdateProcedureTaxRequest
{
    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(
        public readonly float $defaultValue,
    ) {
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $errors = [];
        $value = 0.0;

        if (!array_key_exists('defaultValue', $data)) {
            $errors['defaultValue'] = 'Default value is required';
        } elseif (($error = DefaultValueRule::validate($data['defaultValue'])) !== null) {
            $errors['defaultValue'] = $error;
        } else {
            $value = DefaultValueRule::normalize($data['defaultValue']);
        }

        $dto = new self($value);
        $dto->errors = $errors;

        return $dto;
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
}
