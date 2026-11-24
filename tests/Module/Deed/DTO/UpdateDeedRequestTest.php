<?php

declare(strict_types=1);

namespace Tests\Module\Deed\DTO;

use App\Module\Deed\DTO\UpdateDeedRequest;
use PHPUnit\Framework\TestCase;

final class UpdateDeedRequestTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return $overrides + [
            'deedNumber' => '1234',
            'book' => '10',
            'deedDate' => '2024-01-15',
            'actId' => 5,
            'deedTypeId' => 3,
        ];
    }

    public function testFinishedFlagParticipantsRegistrationsAndUnknownKeysAreIgnored(): void
    {
        $dto = UpdateDeedRequest::fromArray($this->body([
            'isFinished' => true,
            'participants' => [['name' => '', 'typeId' => 99]],
            'registrations' => 'garbage',
            'id' => 7,
            'modifiedBy' => 3,
            'modifiedAt' => 'x',
            'whatever' => 1,
        ]));

        $this->assertTrue($dto->isValid(), json_encode($dto->getErrors()));
    }

    public function testOmittedOptionalsEndUpAsNull(): void
    {
        $dto = UpdateDeedRequest::fromArray($this->body());

        $this->assertNull($dto->fields->subdivisionId);
        $this->assertNull($dto->fields->property);
        $this->assertNull($dto->fields->deliveryDate);
        $this->assertNull($dto->fields->deliveredBy);
        $this->assertNull($dto->fields->observations);
    }

    public function testRequiredFieldsAreStillRequired(): void
    {
        $dto = UpdateDeedRequest::fromArray(null);

        $this->assertFalse($dto->isValid());
        $this->assertSame(
            ['deedNumber', 'book', 'deedDate', 'actId', 'deedTypeId'],
            array_keys($dto->getErrors())
        );
    }
}
