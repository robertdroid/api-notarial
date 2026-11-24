<?php

declare(strict_types=1);

namespace App\Module\User\DTO;

use App\Module\User\Entity\AccessCode;

/**
 * A null property means "field absent, keep the current value".
 * maternalLastName sent as "" or null is normalized to '' (clear the value).
 * username is only checked to be a string here: its format is validated by the Service
 * when it actually changes, so legacy usernames do not block unrelated edits (ADR-004).
 */
final class UpdateUserRequest
{
    private const FIELDS = ['username', 'firstName', 'paternalLastName', 'maternalLastName', 'access', 'status'];

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param ?string[] $access validated, deduplicated, in canonical order
     */
    private function __construct(
        public readonly ?string $username,
        public readonly ?string $firstName,
        public readonly ?string $paternalLastName,
        public readonly ?string $maternalLastName,
        public readonly ?array $access,
        public readonly ?int $status,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $username = $firstName = $paternalLastName = $maternalLastName = $access = $status = null;

        if (array_intersect(self::FIELDS, array_keys($data)) === []) {
            $errors['body'] = 'At least one field (' . implode(', ', self::FIELDS) . ') is required';
        }

        if (array_key_exists('password', $data)) {
            $errors['password'] = 'Password cannot be changed through this endpoint';
        }

        if (array_key_exists('username', $data)) {
            if (!is_string($data['username'])) {
                $errors['username'] = 'Username must be a string';
            } else {
                $username = $data['username'];
            }
        }

        if (array_key_exists('firstName', $data)) {
            if (($error = UserFieldRules::requiredName($data['firstName'], 'First name')) !== null) {
                $errors['firstName'] = $error;
            } else {
                $firstName = trim($data['firstName']);
            }
        }

        if (array_key_exists('paternalLastName', $data)) {
            if (($error = UserFieldRules::requiredName($data['paternalLastName'], 'Paternal last name')) !== null) {
                $errors['paternalLastName'] = $error;
            } else {
                $paternalLastName = trim($data['paternalLastName']);
            }
        }

        if (array_key_exists('maternalLastName', $data)) {
            if (($error = UserFieldRules::optionalName($data['maternalLastName'], 'Maternal last name')) !== null) {
                $errors['maternalLastName'] = $error;
            } else {
                $maternalLastName = UserFieldRules::normalizeOptional($data['maternalLastName']);
            }
        }

        if (array_key_exists('access', $data)) {
            if (($error = UserFieldRules::access($data['access'])) !== null) {
                $errors['access'] = $error;
            } else {
                $access = array_values(array_intersect(AccessCode::values(), $data['access']));
            }
        }

        if (array_key_exists('status', $data)) {
            if (($error = UserFieldRules::status($data['status'])) !== null) {
                $errors['status'] = $error;
            } else {
                $status = $data['status'];
            }
        }

        $instance = new self($username, $firstName, $paternalLastName, $maternalLastName, $access, $status);
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
