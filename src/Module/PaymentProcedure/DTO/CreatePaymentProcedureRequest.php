<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedure\DTO;

final class CreatePaymentProcedureRequest
{
    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(
        public readonly string $procedureName,
        public readonly int $status,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        $rawName = $data['procedureName'] ?? null;
        if ($rawName === null) {
            $errors['procedureName'] = 'Procedure name is required';
        } elseif (($error = ProcedureNameRule::validate($rawName)) !== null) {
            $errors['procedureName'] = $error;
        }

        $status = 1;
        if (array_key_exists('status', $data)) {
            if (!is_int($data['status']) || !in_array($data['status'], [1, 2], true)) {
                $errors['status'] = 'Status must be 1 or 2';
            } else {
                $status = $data['status'];
            }
        }

        $instance = new self(
            procedureName: is_string($rawName) ? trim($rawName) : '',
            status: $status,
        );
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
