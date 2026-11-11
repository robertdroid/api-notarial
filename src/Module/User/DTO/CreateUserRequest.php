<?php

declare(strict_types=1);

namespace App\Module\User\DTO;

use App\Module\User\Entity\AccessCode;

final class CreateUserRequest
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param string[] $access validated, deduplicated, in canonical order
     */
    private function __construct(
        public readonly string $username,
        public readonly string $password,
        public readonly string $firstName,
        public readonly string $paternalLastName,
        public readonly string $maternalLastName,
        public readonly array $access,
        public readonly int $status,
    ) {
    }

    /**
     * Error messages never include the password value.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        $username = '';
        if (!array_key_exists('username', $data)) {
            $errors['username'] = 'Username is required';
        } elseif (($error = UserFieldRules::username($data['username'])) !== null) {
            $errors['username'] = $error;
        } else {
            $username = $data['username'];
        }

        $password = '';
        if (!array_key_exists('password', $data)) {
            $errors['password'] = 'Password is required';
        } elseif (($error = UserFieldRules::password($data['password'])) !== null) {
            $errors['password'] = $error;
        } else {
            $password = $data['password'];
        }

        $firstName = '';
        if (!array_key_exists('firstName', $data)) {
            $errors['firstName'] = 'First name is required';
        } elseif (($error = UserFieldRules::requiredName($data['firstName'], 'First name')) !== null) {
            $errors['firstName'] = $error;
        } else {
            $firstName = trim($data['firstName']);
        }

        $paternalLastName = '';
        if (!array_key_exists('paternalLastName', $data)) {
            $errors['paternalLastName'] = 'Paternal last name is required';
        } elseif (($error = UserFieldRules::requiredName($data['paternalLastName'], 'Paternal last name')) !== null) {
            $errors['paternalLastName'] = $error;
        } else {
            $paternalLastName = trim($data['paternalLastName']);
        }

        $maternalLastName = '';
        if (array_key_exists('maternalLastName', $data)) {
            if (($error = UserFieldRules::optionalName($data['maternalLastName'], 'Maternal last name')) !== null) {
                $errors['maternalLastName'] = $error;
            } else {
                $maternalLastName = UserFieldRules::normalizeOptional($data['maternalLastName']);
            }
        }

        $access = [];
        if (!array_key_exists('access', $data)) {
            $errors['access'] = 'Access is required';
        } elseif (($error = UserFieldRules::access($data['access'])) !== null) {
            $errors['access'] = $error;
        } else {
            $access = array_values(array_intersect(AccessCode::values(), $data['access']));
        }

        $status = 1;
        if (array_key_exists('status', $data)) {
            if (($error = UserFieldRules::status($data['status'])) !== null) {
                $errors['status'] = $error;
            } else {
                $status = $data['status'];
            }
        }

        $instance = new self($username, $password, $firstName, $paternalLastName, $maternalLastName, $access, $status);
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
