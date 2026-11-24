<?php

declare(strict_types=1);

namespace App\Module\Payment\DTO;

final class CreatePaymentRequest
{
    private const TIMEZONE = 'America/Mexico_City';

    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(
        public readonly int $clientId,
        public readonly string $uuid,
        public readonly float $amount,
        public readonly string $paymentDate,
        public readonly string $comments,
    ) {
    }

    /**
     * An omitted or null paymentDate / comments takes its default.
     *
     * @param array<string, mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $errors = [];

        $clientId = 0;
        if (!array_key_exists('clientId', $data)) {
            $errors['clientId'] = 'Client ID is required';
        } elseif (!PaymentFieldRules::positiveInt($data['clientId'])) {
            $errors['clientId'] = 'Client ID must be a positive integer';
        } else {
            $clientId = $data['clientId'];
        }

        $uuid = '';
        if (!array_key_exists('uuid', $data)) {
            $errors['uuid'] = 'UUID is required';
        } elseif (($error = PaymentFieldRules::uuid($data['uuid'])) !== null) {
            $errors['uuid'] = $error;
        } else {
            $uuid = $data['uuid'];
        }

        $amount = 0.0;
        if (!array_key_exists('amount', $data)) {
            $errors['amount'] = 'Amount is required';
        } elseif (($error = PaymentFieldRules::amount($data['amount'])) !== null) {
            $errors['amount'] = $error;
        } else {
            $amount = (float) $data['amount'];
        }

        $paymentDate = self::today();
        if (($data['paymentDate'] ?? null) !== null) {
            if (!PaymentFieldRules::date($data['paymentDate'])) {
                $errors['paymentDate'] = 'Payment date must be a valid date in YYYY-MM-DD format';
            } else {
                $paymentDate = $data['paymentDate'];
            }
        }

        $comments = '';
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

    /** Resolved with an explicit timezone so it does not depend on the server configuration. */
    private static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
    }
}
