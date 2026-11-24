<?php

declare(strict_types=1);

namespace Tests\Module\PaymentTransaction\DTO;

use App\Module\PaymentTransaction\DTO\TransactionDetailItem;
use App\Module\PaymentTransaction\DTO\TransactionDetailsParser;
use PHPUnit\Framework\TestCase;

final class TransactionDetailsParserTest extends TestCase
{
    /**
     * @return array{0: TransactionDetailItem[], 1: array<string, string>}
     */
    private function parse(mixed $details): array
    {
        $errors = [];
        $items = TransactionDetailsParser::parse($details, $errors);

        return [$items, $errors];
    }

    public function testAbsentNullAndEmptyAreEmptyLists(): void
    {
        $this->assertSame([[], []], $this->parse(null));
        $this->assertSame([[], []], $this->parse([]));
    }

    public function testNonListIsRejected(): void
    {
        [, $errors] = $this->parse(['a' => ['taxId' => 1]]);
        $this->assertSame(['details' => 'Details must be an array'], $errors);

        [, $errors] = $this->parse('x');
        $this->assertSame(['details' => 'Details must be an array'], $errors);
    }

    public function testMoreThanFiftyItemsIsRejected(): void
    {
        [, $errors] = $this->parse(array_fill(0, 51, ['taxId' => 1]));
        $this->assertSame(['details' => 'Details must not exceed 50 items'], $errors);

        [$items, $errors] = $this->parse(array_fill(0, 50, ['taxId' => 1]));
        $this->assertSame([], $errors);
        $this->assertCount(50, $items);
    }

    public function testItemThatIsNotAnObjectIsRejected(): void
    {
        [, $errors] = $this->parse([5, [1, 2]]);

        $this->assertSame([
            'details.0' => 'Each detail must be an object',
            'details.1' => 'Each detail must be an object',
        ], $errors);
    }

    public function testDefaultsAreApplied(): void
    {
        [$items, $errors] = $this->parse([['taxId' => 3]]);

        $this->assertSame([], $errors);
        $this->assertEquals(new TransactionDetailItem(3, 1, 0.0, null, ''), $items[0]);
    }

    public function testFullItemIsParsedAndNormalized(): void
    {
        [$items, $errors] = $this->parse([
            ['taxId' => 3, 'quantity' => 2, 'amount' => 10.456, 'date' => '2026-02-03', 'comments' => '  hi  '],
        ]);

        $this->assertSame([], $errors);
        $this->assertEquals(new TransactionDetailItem(3, 2, 10.46, '2026-02-03', 'hi'), $items[0]);
    }

    public function testEachInvalidFieldIsReportedWithItsKey(): void
    {
        [$items, $errors] = $this->parse([
            ['taxId' => '1', 'quantity' => 0, 'amount' => -5, 'date' => '2026-02-30', 'comments' => 5],
        ]);

        $this->assertSame([], $items);
        $this->assertSame([
            'details.0.taxId' => 'Tax ID must be a positive integer',
            'details.0.quantity' => 'Detail quantity must be at least 1',
            'details.0.amount' => 'Detail amount cannot be negative',
            'details.0.date' => 'Detail date must be a valid date in YYYY-MM-DD format',
            'details.0.comments' => 'Detail comments must be a string',
        ], $errors);
    }

    public function testMissingTaxIdIsReported(): void
    {
        [, $errors] = $this->parse([['quantity' => 1]]);

        $this->assertSame(['details.0.taxId' => 'Tax ID must be a positive integer'], $errors);
    }

    public function testNullAmountAndQuantityAreRejected(): void
    {
        [, $errors] = $this->parse([['taxId' => 1, 'quantity' => null, 'amount' => null]]);

        $this->assertSame([
            'details.0.quantity' => 'Detail quantity must be an integer',
            'details.0.amount' => 'Detail amount must be a number',
        ], $errors);
    }

    public function testRepeatedTaxIdIsNotAnError(): void
    {
        [$items, $errors] = $this->parse([['taxId' => 1], ['taxId' => 1, 'amount' => 5]]);

        $this->assertSame([], $errors);
        $this->assertCount(2, $items);
    }

    public function testSubtotalIsRoundedToTwoDecimals(): void
    {
        $this->assertSame(100.01, (new TransactionDetailItem(1, 3, 33.335, null, ''))->subtotal());
    }
}
