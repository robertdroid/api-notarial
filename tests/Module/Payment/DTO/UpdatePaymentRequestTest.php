<?php

declare(strict_types=1);

namespace Tests\Module\Payment\DTO;

use App\Module\Payment\DTO\UpdatePaymentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpdatePaymentRequestTest extends TestCase
{
    public function testOnlyAmountIsRequired(): void
    {
        $dto = UpdatePaymentRequest::fromArray(['amount' => 5500]);

        $this->assertTrue($dto->isValid());
        $this->assertSame(5500.0, $dto->amount);
        $this->assertNull($dto->paymentDate);
        $this->assertNull($dto->comments);
        $this->assertNull($dto->uuid);
        $this->assertNull($dto->clientId);
    }

    public function testFullBody(): void
    {
        $dto = UpdatePaymentRequest::fromArray([
            'clientId' => 5, 'uuid' => 'Us2Gvq', 'amount' => 100.5,
            'paymentDate' => '2024-04-02', 'comments' => ' Ajuste ',
        ]);

        $this->assertTrue($dto->isValid());
        $this->assertSame(5, $dto->clientId);
        $this->assertSame('Us2Gvq', $dto->uuid);
        $this->assertSame('2024-04-02', $dto->paymentDate);
        $this->assertSame('Ajuste', $dto->comments);
    }

    public function testNullPaymentDateAndCommentsMeanKeepTheStoredValue(): void
    {
        $dto = UpdatePaymentRequest::fromArray(['amount' => 1, 'paymentDate' => null, 'comments' => null]);

        $this->assertTrue($dto->isValid());
        $this->assertNull($dto->paymentDate);
        $this->assertNull($dto->comments);
    }

    public function testEmptyCommentsClearTheStoredValue(): void
    {
        $dto = UpdatePaymentRequest::fromArray(['amount' => 1, 'comments' => '']);

        $this->assertTrue($dto->isValid());
        $this->assertSame('', $dto->comments);
    }

    public function testMissingAmount(): void
    {
        foreach ([null, [], ['comments' => 'x']] as $body) {
            $dto = UpdatePaymentRequest::fromArray($body);

            $this->assertFalse($dto->isValid());
            $this->assertSame(['amount' => 'Amount is required'], $dto->getErrors());
        }
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidBodies(): array
    {
        return [
            'amount zero' => [['amount' => 0], 'amount', 'Amount must be greater than zero'],
            'amount negative' => [['amount' => -1], 'amount', 'Amount must be greater than zero'],
            'amount string' => [['amount' => '10'], 'amount', 'Amount must be a number'],
            'amount nan' => [['amount' => NAN], 'amount', 'Amount must be a number'],
            'amount too large' => [['amount' => 1e12], 'amount', 'Amount is too large'],
            'clientId null' => [['clientId' => null], 'clientId', 'Client ID must be a positive integer'],
            'clientId string' => [['clientId' => '5'], 'clientId', 'Client ID must be a positive integer'],
            'uuid null' => [['uuid' => null], 'uuid', 'UUID must be 1 to 20 characters (letters, digits, - or _)'],
            'uuid empty' => [['uuid' => ''], 'uuid', 'UUID must be 1 to 20 characters (letters, digits, - or _)'],
            'date nonexistent' => [['paymentDate' => '2024-02-30'], 'paymentDate', 'Payment date must be a valid date in YYYY-MM-DD format'],
            'comments not string' => [['comments' => ['x']], 'comments', 'Comments must be a string'],
            'comments too long' => [['comments' => str_repeat('a', 65536)], 'comments', 'Comments is too long'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidBodies')]
    public function testInvalidField(array $overrides, string $field, string $message): void
    {
        $dto = UpdatePaymentRequest::fromArray($overrides + ['amount' => 100]);

        $this->assertFalse($dto->isValid());
        $this->assertSame([$field => $message], $dto->getErrors());
    }
}
