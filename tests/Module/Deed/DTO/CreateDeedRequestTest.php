<?php

declare(strict_types=1);

namespace Tests\Module\Deed\DTO;

use App\Module\Deed\DTO\CreateDeedRequest;
use App\Module\Deed\Entity\ParticipantType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreateDeedRequestTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function minimal(array $overrides = []): array
    {
        return $overrides + [
            'deedNumber' => '1234',
            'book' => '10',
            'deedDate' => '2024-01-15',
            'actId' => 5,
            'deedTypeId' => 3,
        ];
    }

    public function testCompletePayloadIsValid(): void
    {
        $dto = CreateDeedRequest::fromArray($this->minimal([
            'subdivisionId' => 2,
            'property' => 'Lote 5, Manzana 3',
            'deliveryDate' => '2024-02-01',
            'deliveredBy' => 'Juan Pérez',
            'observations' => 'Sin observaciones',
            'participants' => [
                ['name' => 'María García', 'typeId' => 1],
                ['name' => 'Pedro López', 'typeId' => 2],
            ],
            'registrations' => [
                ['number' => '12345', 'section' => 'I', 'volume' => '100', 'date' => '2024-01-20'],
            ],
        ]));

        $this->assertTrue($dto->isValid(), json_encode($dto->getErrors()));
        $this->assertSame('1234', $dto->fields->deedNumber);
        $this->assertSame(2, $dto->fields->subdivisionId);
        $this->assertSame('Juan Pérez', $dto->fields->deliveredBy);
        $this->assertCount(2, $dto->participants);
        $this->assertSame(ParticipantType::Buyer, $dto->participants[1]->type);
        $this->assertCount(1, $dto->registrations);
        $this->assertSame('2024-01-20', $dto->registrations[0]->date);
    }

    public function testMinimalPayloadIsValid(): void
    {
        $dto = CreateDeedRequest::fromArray($this->minimal());

        $this->assertTrue($dto->isValid());
        $this->assertNull($dto->fields->subdivisionId);
        $this->assertNull($dto->fields->property);
        $this->assertNull($dto->fields->deliveryDate);
        $this->assertSame([], $dto->participants);
        $this->assertSame([], $dto->registrations);
    }

    public function testNullBodyReportsEveryRequiredField(): void
    {
        $dto = CreateDeedRequest::fromArray(null);

        $this->assertFalse($dto->isValid());
        $this->assertSame(
            ['deedNumber', 'book', 'deedDate', 'actId', 'deedTypeId'],
            array_keys($dto->getErrors())
        );
    }

    /** @return array<string, array{0: string}> */
    public static function requiredFieldProvider(): array
    {
        return [
            'deedNumber' => ['deedNumber'],
            'book' => ['book'],
            'deedDate' => ['deedDate'],
            'actId' => ['actId'],
            'deedTypeId' => ['deedTypeId'],
        ];
    }

    #[DataProvider('requiredFieldProvider')]
    public function testMissingRequiredFieldProducesItsError(string $field): void
    {
        $body = $this->minimal();
        unset($body[$field]);

        $errors = CreateDeedRequest::fromArray($body)->getErrors();

        $this->assertSame([$field], array_keys($errors));
    }

    public function testBlankDeedNumberAndBookAreRequired(): void
    {
        $errors = CreateDeedRequest::fromArray($this->minimal(['deedNumber' => '   ', 'book' => '']))->getErrors();

        $this->assertSame('Deed number is required', $errors['deedNumber']);
        $this->assertSame('Book is required', $errors['book']);
    }

    public function testDeedNumberAndBookAreTrimmed(): void
    {
        $dto = CreateDeedRequest::fromArray($this->minimal(['deedNumber' => ' 16024 BIS ', 'book' => ' X ']));

        $this->assertSame('16024 BIS', $dto->fields->deedNumber);
        $this->assertSame('X', $dto->fields->book);
    }

    /** @return array<string, array{0: string, 1: int, 2: bool}> */
    public static function lengthProvider(): array
    {
        return [
            'deedNumber 50 ok' => ['deedNumber', 50, true],
            'deedNumber 51 too long' => ['deedNumber', 51, false],
            'book 100 ok' => ['book', 100, true],
            'book 101 too long' => ['book', 101, false],
            'deliveredBy 250 ok' => ['deliveredBy', 250, true],
            'deliveredBy 251 too long' => ['deliveredBy', 251, false],
        ];
    }

    #[DataProvider('lengthProvider')]
    public function testLengthLimitsCountMultibyteCharacters(string $field, int $length, bool $valid): void
    {
        // "ñ" is 2 bytes: the limit must count characters, not bytes
        $dto = CreateDeedRequest::fromArray($this->minimal([$field => str_repeat('ñ', $length)]));

        $this->assertSame($valid, $dto->isValid(), json_encode($dto->getErrors()));
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidDateProvider(): array
    {
        return [
            'impossible day' => ['2024-02-30'],
            'dd/mm/yyyy' => ['15/01/2024'],
            'integer' => [20240115],
            'no zero padding' => ['2024-1-5'],
            'datetime' => ['2024-01-15 10:00:00'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidDateProvider')]
    public function testInvalidDatesAreRejected(mixed $date): void
    {
        $errors = CreateDeedRequest::fromArray($this->minimal(['deedDate' => $date, 'deliveryDate' => $date]))->getErrors();

        $this->assertArrayHasKey('deedDate', $errors);
        $this->assertArrayHasKey('deliveryDate', $errors);
    }

    public function testIdsMustBeRealIntegers(): void
    {
        $errors = CreateDeedRequest::fromArray($this->minimal([
            'actId' => '5',
            'deedTypeId' => 0,
            'subdivisionId' => -1,
        ]))->getErrors();

        $this->assertSame('Act ID must be a positive integer', $errors['actId']);
        $this->assertSame('Deed type ID must be a positive integer', $errors['deedTypeId']);
        $this->assertSame('Subdivision ID must be a positive integer', $errors['subdivisionId']);
    }

    public function testBlankOptionalsAreStoredAsNull(): void
    {
        $dto = CreateDeedRequest::fromArray($this->minimal([
            'property' => '   ',
            'deliveredBy' => '',
            'observations' => "\n",
            'subdivisionId' => null,
        ]));

        $this->assertTrue($dto->isValid());
        $this->assertNull($dto->fields->property);
        $this->assertNull($dto->fields->deliveredBy);
        $this->assertNull($dto->fields->observations);
        $this->assertNull($dto->fields->subdivisionId);
    }

    public function testTextFieldsCountBytes(): void
    {
        $ok = CreateDeedRequest::fromArray($this->minimal(['property' => str_repeat('a', 65535)]));
        $tooLong = CreateDeedRequest::fromArray($this->minimal(['observations' => str_repeat('a', 65536)]));

        $this->assertTrue($ok->isValid());
        $this->assertSame('Observations is too long', $tooLong->getErrors()['observations']);
    }

    public function testParticipantsMustBeAList(): void
    {
        $errors = CreateDeedRequest::fromArray($this->minimal(['participants' => ['a' => ['name' => 'X', 'typeId' => 1]]]))->getErrors();

        $this->assertSame('Participants must be an array', $errors['participants']);
    }

    public function testMoreThanFiftyParticipantsAreRejected(): void
    {
        $items = array_fill(0, 51, ['name' => 'X', 'typeId' => 1]);

        $errors = CreateDeedRequest::fromArray($this->minimal(['participants' => $items]))->getErrors();

        $this->assertSame('Participants must not exceed 50 items', $errors['participants']);
    }

    public function testMoreThanTwentyRegistrationsAreRejected(): void
    {
        $items = array_fill(0, 21, ['number' => '1']);

        $errors = CreateDeedRequest::fromArray($this->minimal(['registrations' => $items]))->getErrors();

        $this->assertSame('Registrations must not exceed 20 items', $errors['registrations']);
    }

    public function testParticipantItemErrorsUseIndexedKeys(): void
    {
        $errors = CreateDeedRequest::fromArray($this->minimal([
            'participants' => [
                ['name' => 'Ok', 'typeId' => 1],
                ['name' => '', 'typeId' => 3],
                'not an object',
            ],
        ]))->getErrors();

        $this->assertArrayNotHasKey('participants.0.name', $errors);
        $this->assertSame('Participant name is required', $errors['participants.1.name']);
        $this->assertSame('Invalid participant type', $errors['participants.1.typeId']);
        $this->assertSame('Each participant must be an object', $errors['participants.2']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidTypeIdProvider(): array
    {
        return ['zero' => [0], 'three' => [3], 'numeric string' => ['1'], 'bool' => [true]];
    }

    #[DataProvider('invalidTypeIdProvider')]
    public function testInvalidParticipantTypeIds(mixed $typeId): void
    {
        $errors = CreateDeedRequest::fromArray($this->minimal([
            'participants' => [['name' => 'X', 'typeId' => $typeId]],
        ]))->getErrors();

        $this->assertSame('Invalid participant type', $errors['participants.0.typeId']);
    }

    public function testEmptyRegistrationItemIsRejected(): void
    {
        $errors = CreateDeedRequest::fromArray($this->minimal([
            'registrations' => [['number' => '', 'section' => null]],
        ]))->getErrors();

        $this->assertSame('At least one registration field is required', $errors['registrations.0']);
    }

    public function testRegistrationWithOnlyDateIsValid(): void
    {
        $dto = CreateDeedRequest::fromArray($this->minimal(['registrations' => [['date' => '2024-03-15']]]));

        $this->assertTrue($dto->isValid());
        $this->assertNull($dto->registrations[0]->number);
        $this->assertSame('2024-03-15', $dto->registrations[0]->date);
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $dto = CreateDeedRequest::fromArray($this->minimal(['notaryId' => 99, 'isFinished' => true, 'id' => 5]));

        $this->assertTrue($dto->isValid());
    }
}
