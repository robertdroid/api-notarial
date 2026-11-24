<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedure\DTO;

final class UpdatePaymentProcedureRequest
{
    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(
        public readonly ?string $procedureName,
        public readonly ?int $status,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $procedureName = null;
        $status = null;

        $hasName = array_key_exists('procedureName', $data);
        $hasStatus = array_key_exists('status', $data);

        if (!$hasName && !$hasStatus) {
            $errors['body'] = 'At least one field (procedureName, status) is required';
        }

        if ($hasName) {
            $error = ProcedureNameRule::validate($data['procedureName']);
            if ($error !== null) {
                $errors['procedureName'] = $error;
            } else {
                $procedureName = trim($data['procedureName']);
            }
        }

        if ($hasStatus) {
            if (!is_int($data['status']) || !in_array($data['status'], [1, 2], true)) {
                $errors['status'] = 'Status must be 1 or 2';
            } else {
                $status = $data['status'];
            }
        }

        $instance = new self($procedureName, $status);
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
