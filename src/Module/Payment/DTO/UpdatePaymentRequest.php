<?php

declare(strict_types=1);

namespace App\Module\Payment\DTO;

/**
 * amount is required. paymentDate and comments are optional: null or omitted keeps the stored value.
 * clientId and uuid are optional; when present the Service requires them to match the stored values.
 */
final class UpdatePaymentRequest
{
    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(
        public readonly ?int $clientId,
        public readonly ?string $uuid,
        public readonly float $amount,
        public readonly ?string $paymentDate,
        public readonly ?string $comments,
    ) {
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $errors = [];

        $clientId = null;
        if (array_key_exists('clientId', $data)) {
            if (!PaymentFieldRules::positiveInt($data['clientId'])) {
                $errors['clientId'] = 'Client ID must be a positive integer';
            } else {
                $clientId = $data['clientId'];
            }
        }

        $uuid = null;
        if (array_key_exists('uuid', $data)) {
            if (($error = PaymentFieldRules::uuid($data['uuid'])) !== null) {
                $errors['uuid'] = $error;
            } else {
                $uuid = $data['uuid'];
            }
        }

        $amount = 0.0;
        if (!array_key_exists('amount', $data)) {
            $errors['amount'] = 'Amount is required';
        } elseif (($error = PaymentFieldRules::amount($data['amount'])) !== null) {
            $errors['amount'] = $error;
        } else {
            $amount = (float) $data['amount'];
        }

        $paymentDate = null;
        if (($data['paymentDate'] ?? null) !== null) {
            if (!PaymentFieldRules::date($data['paymentDate'])) {
                $errors['paymentDate'] = 'Payment date must be a valid date in YYYY-MM-DD format';
            } else {
                $paymentDate = $data['paymentDate'];
            }
        }

        $comments = null;
        if (($data['comments'] ?? null) !== null) {
            if (($error = PaymentFieldRules::text($data['comments'], 'Comments')) !== null) {
                $errors['comments'] = $error;
            } else {
                $comments = trim($data['comments']);
            }
        }

        $dto = new self($clientId, $uuid, $amount, $paymentDate, $comments);
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
