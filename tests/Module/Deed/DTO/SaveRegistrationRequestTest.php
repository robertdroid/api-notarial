<?php

declare(strict_types=1);

namespace Tests\Module\Deed\DTO;

use App\Module\Deed\DTO\SaveRegistrationRequest;
use PHPUnit\Framework\TestCase;

final class SaveRegistrationRequestTest extends TestCase
{
    public function testValidFullBody(): void
    {
        $dto = SaveRegistrationRequest::fromArray([
            'number' => '54321', 'section' => 'II', 'volume' => '200', 'date' => '2024-03-15',
        ]);

        $input = $dto->toInput();
        $this->assertTrue($dto->isValid());
        $this->assertSame('54321', $input->number);
        $this->assertSame('II', $input->section);
        $this->assertSame('200', $input->volume);
        $this->assertSame('2024-03-15', $input->date);
    }

    public function testAnySingleFieldIsEnough(): void
    {
        $this->assertTrue(SaveRegistrationRequest::fromArray(['number' => '1'])->isValid());
        $this->assertTrue(SaveRegistrationRequest::fromArray(['section' => 'I'])->isValid());
        $this->assertTrue(SaveRegistrationRequest::fromArray(['volume' => '9'])->isValid());
        $this->assertTrue(SaveRegistrationRequest::fromArray(['date' => '2024-03-15'])->isValid());
    }

    public function testEmptyBodyRequiresAtLeastOneField(): void
    {
        $errors = SaveRegistrationRequest::fromArray([])->getErrors();

        $this->assertSame(['registration' => 'At least one registration field is required'], $errors);
    }

    public function testBlankAndNullFieldsCountAsEmpty(): void
    {
        $errors = SaveRegistrationRequest::fromArray(['number' => '  ', 'section' => null, 'date' => null])->getErrors();

        $this->assertArrayHasKey('registration', $errors);
    }

    public function testFieldsAreTrimmedAndBlankOnesBecomeNull(): void
    {
        $input = SaveRegistrationRequest::fromArray(['number' => ' 12 ', 'section' => ''])->toInput();

        $this->assertSame('12', $input->number);
        $this->assertNull($input->section);
    }

    public function testFieldLimitIsOneHundredCharacters(): void
    {
        $ok = SaveRegistrationRequest::fromArray(['number' => str_repeat('ñ', 100)]);
        $tooLong = SaveRegistrationRequest::fromArray(['number' => str_repeat('ñ', 101)]);

        $this->assertTrue($ok->isValid());
        $this->assertSame('Registration number must not exceed 100 characters', $tooLong->getErrors()['number']);
    }

    public function testInvalidDate(): void
    {
        $errors = SaveRegistrationRequest::fromArray(['number' => '1', 'date' => '2024-02-30'])->getErrors();

        $this->assertSame('Registration date must be a valid date in YYYY-MM-DD format', $errors['date']);
    }

    public function testNonStringFieldIsRejected(): void
    {
        $errors = SaveRegistrationRequest::fromArray(['number' => 12345])->getErrors();

        $this->assertSame('Registration number must be a string', $errors['number']);
    }
}
