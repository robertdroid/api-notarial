<?php

declare(strict_types=1);

namespace Tests\Module\PaymentProcedureTax\DTO;

use App\Module\PaymentProcedureTax\DTO\AddProcedureTaxesRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddProcedureTaxesRequestTest extends TestCase
{
    public function testSingleValid(): void
    {
        $dto = AddProcedureTaxesRequest::fromArray(['taxId' => 4, 'defaultValue' => 500]);

        $this->assertTrue($dto->isValid());
        $this->assertFalse($dto->isBulk);
        $this->assertCount(1, $dto->items);
        $this->assertSame(4, $dto->items[0]->taxId);
        $this->assertSame(500.0, $dto->items[0]->defaultValue);
    }

    public function testSingleWithoutOrNullDefaultValueIsZero(): void
    {
        foreach ([['taxId' => 4], ['taxId' => 4, 'defaultValue' => null]] as $body) {
            $dto = AddProcedureTaxesRequest::fromArray($body);

            $this->assertTrue($dto->isValid());
            $this->assertSame(0.0, $dto->items[0]->defaultValue);
        }
    }

    public function testNegativeDefaultValueBecomesZero(): void
    {
        $dto = AddProcedureTaxesRequest::fromArray(['taxId' => 4, 'defaultValue' => -5]);

        $this->assertTrue($dto->isValid());
        $this->assertSame(0.0, $dto->items[0]->defaultValue);
    }

    public function testBulkValid(): void
    {
        $dto = AddProcedureTaxesRequest::fromArray(['taxes' => [
            ['taxId' => 1, 'defaultValue' => 10.5],
            ['taxId' => 2],
        ]]);

        $this->assertTrue($dto->isValid());
        $this->assertTrue($dto->isBulk);
        $this->assertCount(2, $dto->items);
        $this->assertSame(10.5, $dto->items[0]->defaultValue);
        $this->assertSame(0.0, $dto->items[1]->defaultValue);
    }

    public function testBothKeysAreRejected(): void
    {
        $dto = AddProcedureTaxesRequest::fromArray(['taxId' => 1, 'taxes' => [['taxId' => 2]]]);

        $this->assertSame(['body' => 'Provide either taxId or taxes, not both'], $dto->getErrors());
    }

    public function testNoKeysAreRejected(): void
    {
        $this->assertSame(
            ['body' => 'Either taxId or taxes is required'],
            AddProcedureTaxesRequest::fromArray([])->getErrors()
        );
        $this->assertSame(
            ['body' => 'Either taxId or taxes is required'],
            AddProcedureTaxesRequest::fromArray(null)->getErrors()
        );
    }

    #[DataProvider('invalidTaxIds')]
    public function testSingleRejectsInvalidTaxId(mixed $taxId): void
    {
        $dto = AddProcedureTaxesRequest::fromArray(['taxId' => $taxId]);

        $this->assertFalse($dto->isValid());
        $this->assertSame(['taxId' => 'Tax ID must be a positive integer'], $dto->getErrors());
    }

    /** @return array<string, array{mixed}> */
    public static function invalidTaxIds(): array
    {
        return [
            'numeric string' => ['12'],
            'float' => [1.5],
            'bool' => [true],
            'zero' => [0],
            'negative' => [-1],
            'null' => [null],
            'array' => [[1]],
        ];
    }

    #[DataProvider('invalidDefaultValues')]
    public function testSingleRejectsInvalidDefaultValue(mixed $value): void
    {
        $dto = AddProcedureTaxesRequest::fromArray(['taxId' => 1, 'defaultValue' => $value]);

        $this->assertSame(['defaultValue' => 'Default value must be a number'], $dto->getErrors());
    }

    /** @return array<string, array{mixed}> */
    public static function invalidDefaultValues(): array
    {
        return ['string' => ['500'], 'bool' => [false], 'array' => [[]]];
    }

    public function testBulkEmptyArrayIsRejected(): void
    {
        $dto = AddProcedureTaxesRequest::fromArray(['taxes' => []]);

        $this->assertSame(['taxes' => 'Taxes must be a non-empty array'], $dto->getErrors());
    }

    public function testBulkNonListIsRejected(): void
    {
        $dto = AddProcedureTaxesRequest::fromArray(['taxes' => ['a' => ['taxId' => 1]]]);

        $this->assertSame(['taxes' => 'Taxes must be a non-empty array'], $dto->getErrors());
        $this->assertSame(
            ['taxes' => 'Taxes must be a non-empty array'],
            AddProcedureTaxesRequest::fromArray(['taxes' => 'x'])->getErrors()
        );
    }

    public function testBulkAcceptsExactlyFiftyItems(): void
    {
        $taxes = array_map(fn (int $i) => ['taxId' => $i], range(1, 50));

        $this->assertTrue(AddProcedureTaxesRequest::fromArray(['taxes' => $taxes])->isValid());
    }

    public function testBulkRejectsMoreThanFiftyItems(): void
    {
        $taxes = array_map(fn (int $i) => ['taxId' => $i], range(1, 51));

        $dto = AddProcedureTaxesRequest::fromArray(['taxes' => $taxes]);

        $this->assertSame(['taxes' => 'Taxes must not exceed 50 items'], $dto->getErrors());
    }

    public function testBulkItemMustBeObject(): void
    {
        $dto = AddProcedureTaxesRequest::fromArray(['taxes' => [['taxId' => 1], 5, [1, 2]]]);

        $this->assertSame([
            'taxes.1' => 'Each tax must be an object',
            'taxes.2' => 'Each tax must be an object',
        ], $dto->getErrors());
    }

    public function testBulkReportsIndexedErrorKeys(): void
    {
        $dto = AddProcedureTaxesRequest::fromArray(['taxes' => [
            ['taxId' => 1],
            ['taxId' => '2', 'defaultValue' => 'x'],
            [],
        ]]);

        $this->assertSame([
            'taxes.1.taxId' => 'Tax ID must be a positive integer',
            'taxes.1.defaultValue' => 'Default value must be a number',
            'taxes.2.taxId' => 'Tax ID must be a positive integer',
        ], $dto->getErrors());
    }

    public function testBulkFlagsSecondOccurrenceOfDuplicate(): void
    {
        $dto = AddProcedureTaxesRequest::fromArray(['taxes' => [
            ['taxId' => 5],
            ['taxId' => 6],
            ['taxId' => 5],
        ]]);

        $this->assertSame(['taxes.2.taxId' => 'Duplicate tax ID in request'], $dto->getErrors());
    }
}
