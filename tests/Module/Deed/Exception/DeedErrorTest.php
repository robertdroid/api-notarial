<?php

declare(strict_types=1);

namespace Tests\Module\Deed\Exception;

use App\Module\Deed\Exception\DeedError;
use App\Module\Deed\Exception\DeedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeedErrorTest extends TestCase
{
    /** @return list<array{0: string, 1: string, 2: int, 3: DeedError}> */
    public static function casesProvider(): array
    {
        return [
            ['deed_not_found', 'Deed not found', 404, DeedError::DeedNotFound],
            ['participant_not_found', 'Participant not found', 404, DeedError::ParticipantNotFound],
            ['registration_not_found', 'Registration not found', 404, DeedError::RegistrationNotFound],
            ['deed_already_finished', 'Deed is already finished', 409, DeedError::DeedAlreadyFinished],
            ['participant_already_inactive', 'Participant is already inactive', 409, DeedError::ParticipantAlreadyInactive],
            ['participant_inactive', 'Participant is inactive', 409, DeedError::ParticipantInactive],
            ['act_unavailable', 'Act not found or inactive', 422, DeedError::ActUnavailable],
            ['deed_type_unavailable', 'Deed type not found or inactive', 422, DeedError::DeedTypeUnavailable],
            ['subdivision_unavailable', 'Subdivision not found or inactive', 422, DeedError::SubdivisionUnavailable],
            ['subdivision_not_allowed', 'Deed type does not allow subdivisions', 422, DeedError::SubdivisionNotAllowed],
        ];
    }

    #[DataProvider('casesProvider')]
    public function testCodeMessageAndStatus(string $code, string $message, int $status, DeedError $error): void
    {
        $this->assertSame($code, $error->value);
        $this->assertSame($message, $error->message());
        $this->assertSame($status, $error->httpStatus());
    }

    public function testRegistrationLimitMessageUsesTheLimit(): void
    {
        $error = DeedError::RegistrationLimitExceeded;

        $this->assertSame('registration_limit_exceeded', $error->value);
        $this->assertSame(422, $error->httpStatus());
        $this->assertSame('Deed type allows at most 2 registrations', $error->message(['maxRegistrations' => 2]));
    }

    public function testExceptionCarriesErrorAndDetails(): void
    {
        $details = ['maxRegistrations' => 3, 'currentRegistrations' => 4];
        $exception = new DeedException(DeedError::RegistrationLimitExceeded, $details);

        $this->assertSame(DeedError::RegistrationLimitExceeded, $exception->error);
        $this->assertSame($details, $exception->details);
        $this->assertSame('Deed type allows at most 3 registrations', $exception->getMessage());
    }

    public function testEveryCaseHasMessageAndStatus(): void
    {
        foreach (DeedError::cases() as $case) {
            $this->assertNotSame('', $case->message());
            $this->assertContains($case->httpStatus(), [404, 409, 422]);
        }
    }
}
