<?php

declare(strict_types=1);

namespace Tests\Module\Payment\DTO;

use App\Module\Payment\DTO\CreatePaymentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreatePaymentRequestTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private static function body(array $overrides = []): array
    {
        return $overrides + ['clientId' => 5, 'uuid' => 'Us2Gvq', 'amount' => 5000];
    }

    public function testValidFullBody(): void
    {
        $dto = CreatePaymentRequest::fromArray(self::body([
            'paymentDate' => '2024-04-02', 'comments' => '  Adelanto  ',
        ]));

        $this->assertTrue($dto->isValid());
        $this->assertSame(5, $dto->clientId);
        $this->assertSame('Us2Gvq', $dto->uuid);
        $this->assertSame(5000.0, $dto->amount);
        $this->assertSame('2024-04-02', $dto->paymentDate);
        $this->assertSame('Adelanto', $dto->comments);
    }

    public function testDefaultsWhenOptionalFieldsAreOmitted(): void
    {
        $dto = CreatePaymentRequest::fromArray(self::body());
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m-d');

        $this->assertTrue($dto->isValid());
        $this->assertSame($today, $dto->paymentDate);
        $this->assertSame('', $dto->comments);
    }

    public function testNullOptionalFieldsTakeTheirDefaults(): void
    {
        $dto = CreatePaymentRequest::fromArray(self::body(['paymentDate' => null, 'comments' => null]));

        $this->assertTrue($dto->isValid());
        $this->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}$/', $dto->paymentDate));
        $this->assertSame('', $dto->comments);
    }

    public function testPastAndFutureDatesAreAccepted(): void
    {
        $this->assertTrue(CreatePaymentRequest::fromArray(self::body(['paymentDate' => '1999-12-31']))->isValid());
        $this->assertTrue(CreatePaymentRequest::fromArray(self::body(['paymentDate' => '2999-12-31']))->isValid());
    }

    public function testEmptyBodyReportsRequiredFields(): void
    {
        foreach ([null, []] as $body) {
            $dto = CreatePaymentRequest::fromArray($body);

            $this->assertFalse($dto->isValid());
            $this->assertSame(['clientId', 'uuid', 'amount'], array_keys($dto->getErrors()));
        }
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidBodies(): array
    {
        return [
            'amount zero' => [['amount' => 0], 'amount', 'Amount must be greater than zero'],
            'amount negative' => [['amount' => -10.5], 'amount', 'Amount must be greater than zero'],
            'amount numeric string' => [['amount' => '5000'], 'amount', 'Amount must be a number'],
            'amount null' => [['amount' => null], 'amount', 'Amount must be a number'],
            'amount too large' => [['amount' => 1e12], 'amount', 'Amount is too large'],
            'clientId string' => [['clientId' => '5'], 'clientId', 'Client ID must be a positive integer'],
            'clientId zero' => [['clientId' => 0], 'clientId', 'Client ID must be a positive integer'],
            'uuid empty' => [['uuid' => ''], 'uuid', 'UUID must be 1 to 20 characters (letters, digits, - or _)'],
            'uuid spaces' => [['uuid' => '   '], 'uuid', 'UUID must be 1 to 20 characters (letters, digits, - or _)'],
            'uuid too long' => [['uuid' => str_repeat('a', 21)], 'uuid', 'UUID must be 1 to 20 characters (letters, digits, - or _)'],
            'uuid array' => [['uuid' => ['x']], 'uuid', 'UUID must be 1 to 20 characters (letters, digits, - or _)'],
            'date nonexistent' => [['paymentDate' => '2024-02-30'], 'paymentDate', 'Payment date must be a valid date in YYYY-MM-DD format'],
            'date not padded' => [['paymentDate' => '2024-1-5'], 'paymentDate', 'Payment date must be a valid date in YYYY-MM-DD format'],
            'date int' => [['paymentDate' => 20240402], 'paymentDate', 'Payment date must be a valid date in YYYY-MM-DD format'],
            'comments not string' => [['comments' => 5], 'comments', 'Comments must be a string'],
            'comments too long' => [['comments' => str_repeat('a', 65536)], 'comments', 'Comments is too long'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidBodies')]
    public function testInvalidField(array $overrides, string $field, string $message): void
    {
        $dto = CreatePaymentRequest::fromArray(self::body($overrides));

        $this->assertFalse($dto->isValid());
        $this->assertSame([$field => $message], $dto->getErrors());
    }

    public function testAmountIsNotRounded(): void
    {
        $dto = CreatePaymentRequest::fromArray(self::body(['amount' => 10.555]));

        $this->assertTrue($dto->isValid());
        $this->assertSame(10.555, $dto->amount);
    }

    public function testUuidKeepsItsCase(): void
    {
        $this->assertSame('AbC', CreatePaymentRequest::fromArray(self::body(['uuid' => 'AbC']))->uuid);
    }
}
