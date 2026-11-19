<?php

declare(strict_types=1);

namespace App\Module\PaymentStatement\DTO;

use App\Module\Payment\DTO\PaymentFieldRules;

final class StatementQuery
{
    private const TIMEZONE = 'America/Mexico_City';

    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
    ) {
    }

    /**
     * Absent, null or empty from / to mean "not sent". Unknown params are ignored.
     *
     * @param array<string, mixed> $params
     */
    public static function fromArray(array $params): self
    {
        $errors = [];

        $from = self::parseDate($params['from'] ?? null, 'From', 'from', $errors);
        $to = self::parseDate($params['to'] ?? null, 'To', 'to', $errors);

        // The range is only compared when both dates are valid
        if ($from !== null && $to !== null && $to < $from) {
            $errors['to'] = 'To must not be before from';
        }

        $query = new self($from, $to);
        $query->errors = $errors;

        return $query;
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

    /** Inclusive start of the range: the first instant of the "from" day. */
    public function fromBoundary(): ?string
    {
        return $this->from !== null ? $this->from . ' 00:00:00' : null;
    }

    /** Exclusive end of the range: the first instant of the day after "to". */
    public function toExclusiveBoundary(): ?string
    {
        if ($this->to === null) {
            return null;
        }

        return (new \DateTimeImmutable($this->to . ' 00:00:00', new \DateTimeZone(self::TIMEZONE)))
            ->modify('+1 day')
            ->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, string> $errors
     */
    private static function parseDate(mixed $value, string $label, string $field, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Rejects arrays (from[]=x), 2026-02-31 and 2026-3-1
        if (!PaymentFieldRules::date($value)) {
            $errors[$field] = "$label must be a valid date in YYYY-MM-DD format";

            return null;
        }

        return $value;
    }
}
