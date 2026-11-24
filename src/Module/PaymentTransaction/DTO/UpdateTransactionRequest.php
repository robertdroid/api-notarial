<?php

declare(strict_types=1);

namespace App\Module\PaymentTransaction\DTO;

/**
 * Full replacement: an omitted optional field takes its default.
 * clientId and uuid are optional; when present the Service requires them to match the stored values.
 */
final class UpdateTransactionRequest
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @param TransactionDetailItem[] $details */
    private function __construct(
        public readonly ?int $clientId,
        public readonly int $procedureId,
        public readonly ?string $uuid,
        public readonly string $date,
        public readonly float $fee,
        public readonly float $taxes,
        public readonly string $reference,
        public readonly string $responsible,
        public readonly string $comments,
        public readonly array $details,
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
            if (!TransactionFieldRules::positiveInt($data['clientId'])) {
                $errors['clientId'] = 'Client ID must be a positive integer';
            } else {
                $clientId = $data['clientId'];
            }
        }

        $uuid = null;
        if (array_key_exists('uuid', $data)) {
            if (($error = TransactionFieldRules::uuid($data['uuid'])) !== null) {
                $errors['uuid'] = $error;
            } else {
                $uuid = $data['uuid'];
            }
        }

        $body = TransactionBodyParser::parse($data, $errors);

        $dto = new self(
            clientId: $clientId,
            procedureId: $body['procedureId'],
            uuid: $uuid,
            date: $body['date'],
            fee: $body['fee'],
            taxes: $body['taxes'],
            reference: $body['reference'],
            responsible: $body['responsible'],
            comments: $body['comments'],
            details: $body['details'],
        );
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
