<?php

declare(strict_types=1);

namespace App\Module\DeedType\DTO;

final class UpdateDeedTypeRequest
{
    public readonly string $name;
    public readonly string $description;
    public readonly int $maxInscriptions;
    public readonly bool $enableSubdivisions;
    public readonly int $status;

    private array $errors = [];

    private function __construct(
        string $name,
        string $description,
        int $maxInscriptions,
        bool $enableSubdivisions,
        int $status,
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->maxInscriptions = $maxInscriptions;
        $this->enableSubdivisions = $enableSubdivisions;
        $this->status = $status;
    }

    public static function fromArray(array $data): self
    {
        $instance = new self(
            name: $data['name'] ?? '',
            description: $data['description'] ?? '',
            maxInscriptions: isset($data['maxInscriptions']) ? (int) $data['maxInscriptions'] : 1,
            enableSubdivisions: isset($data['enableSubdivisions']) ? (bool) $data['enableSubdivisions'] : false,
            status: isset($data['status']) ? (int) $data['status'] : 0,
        );

        $instance->validate();

        return $instance;
    }

    private function validate(): void
    {
        if (empty(trim($this->name))) {
            $this->errors['name'] = 'Deed type name is required';
        } elseif (mb_strlen($this->name) > 200) {
            $this->errors['name'] = 'Deed type name must not exceed 200 characters';
        }

        if (empty(trim($this->description))) {
            $this->errors['description'] = 'Description is required';
        } elseif (mb_strlen($this->description) > 500) {
            $this->errors['description'] = 'Description must not exceed 500 characters';
        }

        if ($this->maxInscriptions <= 0) {
            $this->errors['maxInscriptions'] = 'Max inscriptions must be greater than 0';
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
