<?php

declare(strict_types=1);

namespace Tests\Module\Receipt\Service;

use App\Module\Receipt\Service\AmountInWordsConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountInWordsConverterTest extends TestCase
{
    private AmountInWordsConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new AmountInWordsConverter();
    }

    /** @return array<string, array{float, string}> */
    public static function amounts(): array
    {
        return [
            'zero' => [0.0, 'CERO PESOS 00/100 M.N.'],
            'one peso is singular' => [1.0, 'UN PESO 00/100 M.N.'],
            'two' => [2.0, 'DOS PESOS 00/100 M.N.'],
            'sixteen' => [16.0, 'DIECISÉIS PESOS 00/100 M.N.'],
            'twenty' => [20.0, 'VEINTE PESOS 00/100 M.N.'],
            'twenty one' => [21.0, 'VEINTIÚN PESOS 00/100 M.N.'],
            'thirty one' => [31.0, 'TREINTA Y UN PESOS 00/100 M.N.'],
            'forty five' => [45.0, 'CUARENTA Y CINCO PESOS 00/100 M.N.'],
            'one hundred exactly' => [100.0, 'CIEN PESOS 00/100 M.N.'],
            'one hundred one' => [101.0, 'CIENTO UN PESOS 00/100 M.N.'],
            'five hundred' => [500.0, 'QUINIENTOS PESOS 00/100 M.N.'],
            'nine hundred ninety nine' => [999.0, 'NOVECIENTOS NOVENTA Y NUEVE PESOS 00/100 M.N.'],
            'one thousand has no UN' => [1000.0, 'MIL PESOS 00/100 M.N.'],
            'one thousand one' => [1001.0, 'MIL UN PESOS 00/100 M.N.'],
            'one thousand two hundred' => [1200.0, 'MIL DOSCIENTOS PESOS 00/100 M.N.'],
            'twenty one thousand' => [21000.0, 'VEINTIÚN MIL PESOS 00/100 M.N.'],
            'thirty one thousand' => [31000.0, 'TREINTA Y UN MIL PESOS 00/100 M.N.'],
            'one hundred thousand' => [100000.0, 'CIEN MIL PESOS 00/100 M.N.'],
            'five thousand with cents' => [5000.50, 'CINCO MIL PESOS 50/100 M.N.'],
            'cents only' => [0.99, 'CERO PESOS 99/100 M.N.'],
            'one million' => [1000000.0, 'UN MILLÓN PESOS 00/100 M.N.'],
            'two million' => [2000000.0, 'DOS MILLONES PESOS 00/100 M.N.'],
            'twenty one million' => [21000000.0, 'VEINTIÚN MILLONES PESOS 00/100 M.N.'],
            'million and thousands' => [1234567.89, 'UN MILLÓN DOSCIENTOS TREINTA Y CUATRO MIL QUINIENTOS SESENTA Y SIETE PESOS 89/100 M.N.'],
            'maximum' => [
                999999999.99,
                'NOVECIENTOS NOVENTA Y NUEVE MILLONES NOVECIENTOS NOVENTA Y NUEVE MIL NOVECIENTOS NOVENTA Y NUEVE PESOS 99/100 M.N.',
            ],
        ];
    }

    #[DataProvider('amounts')]
    public function testConvertsAmountToWords(float $amount, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($amount));
    }

    public function testFloatErrorsDoNotLeakIntoCents(): void
    {
        // 0.1 + 0.2 is 0.30000000000000004 and 1.15 * 100 is 114.99999999999999
        $this->assertSame('CERO PESOS 30/100 M.N.', $this->converter->convert(0.1 + 0.2));
        $this->assertSame('UN PESO 15/100 M.N.', $this->converter->convert(1.15));
        $this->assertSame('CIEN PESOS 10/100 M.N.', $this->converter->convert(100.10));
    }

    /** @return array<string, array{float}> */
    public static function invalidAmounts(): array
    {
        return [
            'negative' => [-0.01],
            'above the maximum' => [1000000000.0],
            'infinite' => [INF],
            'not a number' => [NAN],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function testRejectsOutOfRangeAmounts(float $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->converter->convert($amount);
    }
}
