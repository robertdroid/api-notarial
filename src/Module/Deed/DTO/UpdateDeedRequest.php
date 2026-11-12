<?php

declare(strict_types=1);

namespace App\Module\Deed\DTO;

/**
 * Body of PUT /deeds/{id}: full replacement of the editable fields (ADR-006).
 * Omitted optional fields end up as null. `participants`, `registrations`, `isFinished`
 * and any unknown key are ignored.
 */
final class UpdateDeedRequest
{
    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(public readonly DeedFields $fields)
    {
    }

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): self
    {
        $errors = [];
        $dto = new self(DeedFields::parse($data ?? [], $errors));
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
