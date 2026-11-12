<?php

declare(strict_types=1);

namespace App\Module\Deed\DTO;

/**
 * Body of POST and PUT /deeds/{id}/registrations (PUT is a full replacement).
 */
final class SaveRegistrationRequest
{
    private const TEXT_FIELDS = [
        'number' => 'Registration number',
        'section' => 'Registration section',
        'volume' => 'Registration volume',
    ];

    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(private readonly ?RegistrationInput $input)
    {
    }

    /** @param array<string, mixed>|null $body */
    public static function fromArray(?array $body): self
    {
        $errors = [];
        $input = self::validate($body ?? [], '', $errors);

        $dto = new self($input);
        $dto->errors = $errors;

        return $dto;
    }

    /**
     * Validates one registration. Also used for the items of CreateDeedRequest.registrations.
     *
     * @param array<string, string> $errors
     */
    public static function validate(mixed $item, string $prefix, array &$errors): ?RegistrationInput
    {
        if (!is_array($item) || ($item !== [] && array_is_list($item))) {
            $errors[rtrim($prefix, '.')] = 'Each registration must be an object';

            return null;
        }

        $valid = true;
        $values = [];

        foreach (self::TEXT_FIELDS as $field => $label) {
            $values[$field] = null;
            if (($item[$field] ?? null) === null) {
                continue;
            }

            $error = DeedFieldRules::string($item[$field], DeedFieldRules::MAX_REGISTRATION_FIELD, $label);
            if ($error !== null) {
                $errors[$prefix . $field] = $error;
                $valid = false;
            } else {
                $values[$field] = DeedFieldRules::normalizeOptional($item[$field]);
            }
        }

        $date = $item['date'] ?? null;
        if ($date !== null && !DeedFieldRules::date($date)) {
            $errors[$prefix . 'date'] = 'Registration date must be a valid date in YYYY-MM-DD format';
            $valid = false;
            $date = null;
        }

        if ($valid && $values['number'] === null && $values['section'] === null
            && $values['volume'] === null && $date === null) {
            $errors[$prefix === '' ? 'registration' : rtrim($prefix, '.')] = 'At least one registration field is required';
            $valid = false;
        }

        return $valid ? new RegistrationInput($values['number'], $values['section'], $values['volume'], $date) : null;
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

    public function toInput(): RegistrationInput
    {
        return $this->input ?? throw new \LogicException('Request is not valid');
    }
}
