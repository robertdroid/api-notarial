<?php

declare(strict_types=1);

namespace Tests\Module\Deed\DTO;

use App\Module\Deed\DTO\SaveParticipantRequest;
use App\Module\Deed\Entity\ParticipantType;
use PHPUnit\Framework\TestCase;

final class SaveParticipantRequestTest extends TestCase
{
    public function testValidBody(): void
    {
        $dto = SaveParticipantRequest::fromArray(['name' => '  Carlos Ruiz ', 'typeId' => 1]);

        $this->assertTrue($dto->isValid());
        $this->assertSame('Carlos Ruiz', $dto->toInput()->name);
        $this->assertSame(ParticipantType::Seller, $dto->toInput()->type);
    }

    public function testNameAndTypeAreRequired(): void
    {
        $errors = SaveParticipantRequest::fromArray([])->getErrors();

        $this->assertSame('Participant name is required', $errors['name']);
        $this->assertSame('Participant type is required', $errors['typeId']);
    }

    public function testBlankNameIsRequired(): void
    {
        $errors = SaveParticipantRequest::fromArray(['name' => '   ', 'typeId' => 2])->getErrors();

        $this->assertSame('Participant name is required', $errors['name']);
    }

    public function testInvalidTypeId(): void
    {
        $errors = SaveParticipantRequest::fromArray(['name' => 'X', 'typeId' => 99])->getErrors();

        $this->assertSame(['typeId' => 'Invalid participant type'], $errors);
    }

    public function testNameLimitIsTwoHundredFiftyCharactersAndIsNotTruncated(): void
    {
        $ok = SaveParticipantRequest::fromArray(['name' => str_repeat('ñ', 250), 'typeId' => 1]);
        $tooLong = SaveParticipantRequest::fromArray(['name' => str_repeat('ñ', 251), 'typeId' => 1]);

        $this->assertTrue($ok->isValid());
        $this->assertSame('Participant name must not exceed 250 characters', $tooLong->getErrors()['name']);
    }

    public function testNonStringNameIsRejected(): void
    {
        $errors = SaveParticipantRequest::fromArray(['name' => 123, 'typeId' => 1])->getErrors();

        $this->assertSame('Participant name must be a string', $errors['name']);
    }

    public function testToInputOnInvalidRequestThrows(): void
    {
        $this->expectException(\LogicException::class);

        SaveParticipantRequest::fromArray([])->toInput();
    }
}
