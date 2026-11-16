<?php

declare(strict_types=1);

namespace App\Module\User\DTO;

final class ChangePasswordRequest
{
    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(public readonly string $password)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $password = '';

        if (!array_key_exists('password', $data)) {
            $errors['password'] = 'Password is required';
        } elseif (($error = UserFieldRules::password($data['password'])) !== null) {
            $errors['password'] = $error;
        } else {
            $password = $data['password'];
        }

        $instance = new self($password);
        $instance->errors = $errors;

        return $instance;
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
