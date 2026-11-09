<?php

declare(strict_types=1);

namespace App\Module\Act\DTO;

final class CreateActRequest
{
    public readonly string $actName;
    public readonly string $actDescription;
    public readonly int $status;

    private array $errors = [];

    private function __construct(
        string $actName,
        string $actDescription,
        int $status,
    ) {
        $this->actName = $actName;
        $this->actDescription = $actDescription;
        $this->status = $status;
    }

    public static function fromArray(array $data): self
    {
        $instance = new self(
            actName: $data['actName'] ?? '',
            actDescription: $data['actDescription'] ?? '',
            status: isset($data['status']) ? (int) $data['status'] : 1,
        );

        $instance->validate();

        return $instance;
    }

    private function validate(): void
    {
        if (empty(trim($this->actName))) {
            $this->errors['actName'] = 'Act name is required';
        } elseif (mb_strlen($this->actName) > 200) {
            $this->errors['actName'] = 'Act name must not exceed 200 characters';
        }

        if (empty(trim($this->actDescription))) {
            $this->errors['actDescription'] = 'Act description is required';
        } elseif (mb_strlen($this->actDescription) > 500) {
            $this->errors['actDescription'] = 'Act description must not exceed 500 characters';
        }

        if (!in_array($this->status, [1, 2], true)) {
            $this->errors['status'] = 'Status must be 1 or 2';
        }
    }

    public function isValid(): bool
    {
        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
