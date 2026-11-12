<?php

declare(strict_types=1);

namespace App\Module\Deed\DTO;

use App\Module\Deed\Entity\ParticipantType;

/**
 * Body of POST and PUT /deeds/{id}/participants (PUT is a full replacement).
 */
final class SaveParticipantRequest
{
    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(private readonly ?ParticipantInput $input)
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
     * Validates one participant. Also used for the items of CreateDeedRequest.participants.
     *
     * @param array<string, string> $errors
     */
    public static function validate(mixed $item, string $prefix, array &$errors): ?ParticipantInput
    {
        if (!is_array($item) || ($item !== [] && array_is_list($item))) {
            $errors[rtrim($prefix, '.')] = 'Each participant must be an object';

            return null;
        }

        $valid = true;

        $name = '';
        if (!array_key_exists('name', $item) || $item['name'] === null) {
            $errors[$prefix . 'name'] = 'Participant name is required';
            $valid = false;
        } elseif (($error = DeedFieldRules::string($item['name'], DeedFieldRules::MAX_PARTICIPANT_NAME, 'Participant name')) !== null) {
            $errors[$prefix . 'name'] = $error;
            $valid = false;
        } else {
            $name = trim($item['name']);
            if ($name === '') {
                $errors[$prefix . 'name'] = 'Participant name is required';
                $valid = false;
            }
        }

        $type = null;
        if (!array_key_exists('typeId', $item) || $item['typeId'] === null) {
            $errors[$prefix . 'typeId'] = 'Participant type is required';
            $valid = false;
        } else {
            $type = is_int($item['typeId']) ? ParticipantType::tryFrom($item['typeId']) : null;
            if ($type === null) {
                $errors[$prefix . 'typeId'] = 'Invalid participant type';
                $valid = false;
            }
        }

        return $valid && $type !== null ? new ParticipantInput($name, $type) : null;
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

    public function toInput(): ParticipantInput
    {
        return $this->input ?? throw new \LogicException('Request is not valid');
    }
}
