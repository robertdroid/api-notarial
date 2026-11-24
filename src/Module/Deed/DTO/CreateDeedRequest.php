<?php

declare(strict_types=1);

namespace App\Module\Deed\DTO;

/**
 * Body of POST /deeds: the deed plus optional participants and registrations.
 */
final class CreateDeedRequest
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param ParticipantInput[] $participants
     * @param RegistrationInput[] $registrations
     */
    private function __construct(
        public readonly DeedFields $fields,
        public readonly array $participants,
        public readonly array $registrations,
    ) {
    }

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $errors = [];

        $fields = DeedFields::parse($data, $errors);
        $participants = self::parseList(
            $data['participants'] ?? null,
            'participants',
            'Participants',
            DeedFieldRules::MAX_PARTICIPANTS,
            SaveParticipantRequest::validate(...),
            $errors,
        );
        $registrations = self::parseList(
            $data['registrations'] ?? null,
            'registrations',
            'Registrations',
            DeedFieldRules::MAX_REGISTRATIONS,
            SaveRegistrationRequest::validate(...),
            $errors,
        );

        $dto = new self($fields, $participants, $registrations);
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

    /**
     * @template T
     * @param callable(mixed, string, array<string, string>): ?T $validateItem
     * @param array<string, string> $errors
     * @return T[]
     */
    private static function parseList(
        mixed $list,
        string $key,
        string $label,
        int $max,
        callable $validateItem,
        array &$errors,
    ): array {
        if ($list === null) {
            return [];
        }

        if (!is_array($list) || !array_is_list($list)) {
            $errors[$key] = "$label must be an array";

            return [];
        }

        if (count($list) > $max) {
            $errors[$key] = "$label must not exceed $max items";

            return [];
        }

        $items = [];
        foreach ($list as $index => $item) {
            $parsed = $validateItem($item, "$key.$index.", $errors);
            if ($parsed !== null) {
                $items[] = $parsed;
            }
        }

        return $items;
    }
}
