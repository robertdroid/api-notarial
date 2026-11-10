<?php

declare(strict_types=1);

namespace App\Module\PaymentClient\DTO;

/**
 * A null property means "field absent, keep the current value".
 * phone/email sent as "" or null are normalized to '' (clear the value).
 */
final class UpdatePaymentClientRequest
{
    private const FIELDS = ['fullName', 'phone', 'email', 'status'];

    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(
        public readonly ?string $fullName,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?int $status,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $fullName = $phone = $email = $status = null;

        if (array_intersect(self::FIELDS, array_keys($data)) === []) {
            $errors['body'] = 'At least one field (fullName, phone, email, status) is required';
        }

        if (array_key_exists('fullName', $data)) {
            if (($error = ClientFieldRules::fullName($data['fullName'])) !== null) {
                $errors['fullName'] = $error;
            } else {
                $fullName = trim($data['fullName']);
            }
        }

        if (array_key_exists('phone', $data)) {
            if (($error = ClientFieldRules::phone($data['phone'])) !== null) {
                $errors['phone'] = $error;
            } else {
                $phone = ClientFieldRules::normalizeOptional($data['phone']);
            }
        }

        if (array_key_exists('email', $data)) {
            if (($error = ClientFieldRules::email($data['email'])) !== null) {
                $errors['email'] = $error;
            } else {
                $email = ClientFieldRules::normalizeOptional($data['email']);
            }
        }

        if (array_key_exists('status', $data)) {
            if (($error = ClientFieldRules::status($data['status'])) !== null) {
                $errors['status'] = $error;
            } else {
                $status = $data['status'];
            }
        }

        $instance = new self($fullName, $phone, $email, $status);
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
