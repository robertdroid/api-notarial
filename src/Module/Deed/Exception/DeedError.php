<?php

declare(strict_types=1);

namespace App\Module\Deed\Exception;

/**
 * Domain errors of the deeds module. The enum value is the stable `code` the frontend relies on.
 */
enum DeedError: string
{
    case DeedNotFound = 'deed_not_found';
    case ParticipantNotFound = 'participant_not_found';
    case RegistrationNotFound = 'registration_not_found';
    case DeedAlreadyFinished = 'deed_already_finished';
    case ParticipantAlreadyInactive = 'participant_already_inactive';
    case ParticipantInactive = 'participant_inactive';
    case ActUnavailable = 'act_unavailable';
    case DeedTypeUnavailable = 'deed_type_unavailable';
    case SubdivisionUnavailable = 'subdivision_unavailable';
    case SubdivisionNotAllowed = 'subdivision_not_allowed';
    case RegistrationLimitExceeded = 'registration_limit_exceeded';

    /** @param array<string, int> $details */
    public function message(array $details = []): string
    {
        return match ($this) {
            self::DeedNotFound => 'Deed not found',
            self::ParticipantNotFound => 'Participant not found',
            self::RegistrationNotFound => 'Registration not found',
            self::DeedAlreadyFinished => 'Deed is already finished',
            self::ParticipantAlreadyInactive => 'Participant is already inactive',
            self::ParticipantInactive => 'Participant is inactive',
            self::ActUnavailable => 'Act not found or inactive',
            self::DeedTypeUnavailable => 'Deed type not found or inactive',
            self::SubdivisionUnavailable => 'Subdivision not found or inactive',
            self::SubdivisionNotAllowed => 'Deed type does not allow subdivisions',
            self::RegistrationLimitExceeded => sprintf(
                'Deed type allows at most %d registrations',
                $details['maxRegistrations'] ?? 0
            ),
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::DeedNotFound, self::ParticipantNotFound, self::RegistrationNotFound => 404,
            self::DeedAlreadyFinished, self::ParticipantAlreadyInactive, self::ParticipantInactive => 409,
            self::ActUnavailable,
            self::DeedTypeUnavailable,
            self::SubdivisionUnavailable,
            self::SubdivisionNotAllowed,
            self::RegistrationLimitExceeded => 422,
        };
    }
}
