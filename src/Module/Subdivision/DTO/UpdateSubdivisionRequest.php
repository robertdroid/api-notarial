<?php

declare(strict_types=1);

namespace App\Module\Subdivision\DTO;

final class UpdateSubdivisionRequest
{
    public readonly string $name;
    public readonly int $status;

    private array $errors = [];

    private function __construct(
        string $name,
        int $status,
    ) {
        $this->name = $name;
        $this->status = $status;
    }

    public static function fromArray(array $data): self
    {
        $instance = new self(
            name: $data['name'] ?? '',
            status: isset($data['status']) ? (int) $data['status'] : 0,
        );

        $instance->validate();

        return $instance;
    }

    private function validate(): void
    {
        if (empty(trim($this->name))) {
            $this->errors['name'] = 'Subdivision name is required';
        } elseif (mb_strlen($this->name) > 500) {
            $this->errors['name'] = 'Subdivision name must not exceed 500 characters';
        }

        if (!in_array($this->status, [1, 2], true)) {
            $this->errors['status'] = 'Status is required and must be 1 or 2';
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
