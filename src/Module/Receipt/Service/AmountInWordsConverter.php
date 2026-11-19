<?php

declare(strict_types=1);

namespace App\Module\Receipt\Service;

use App\Module\Payment\DTO\PaymentFieldRules;

/**
 * Converts an amount in Mexican pesos to words: 1200 => "MIL DOSCIENTOS PESOS 00/100 M.N.".
 *
 * Works in integer cents so float errors (0.1 + 0.2) never leak into the text.
 * Supported range: 0 to PaymentFieldRules::MAX_MONEY (999,999,999.99).
 */
final class AmountInWordsConverter
{
    private const UNITS = [
        0 => 'CERO', 1 => 'UNO', 2 => 'DOS', 3 => 'TRES', 4 => 'CUATRO', 5 => 'CINCO',
        6 => 'SEIS', 7 => 'SIETE', 8 => 'OCHO', 9 => 'NUEVE', 10 => 'DIEZ', 11 => 'ONCE',
        12 => 'DOCE', 13 => 'TRECE', 14 => 'CATORCE', 15 => 'QUINCE', 16 => 'DIECISÉIS',
        17 => 'DIECISIETE', 18 => 'DIECIOCHO', 19 => 'DIECINUEVE', 20 => 'VEINTE',
        21 => 'VEINTIUNO', 22 => 'VEINTIDÓS', 23 => 'VEINTITRÉS', 24 => 'VEINTICUATRO',
        25 => 'VEINTICINCO', 26 => 'VEINTISÉIS', 27 => 'VEINTISIETE', 28 => 'VEINTIOCHO',
        29 => 'VEINTINUEVE',
    ];

    private const TENS = [
        3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA',
        7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA',
    ];

    private const HUNDREDS = [
        1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS',
        5 => 'QUINIENTOS', 6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS',
        9 => 'NOVECIENTOS',
    ];

    public function convert(float $amount): string
    {
        if (!is_finite($amount) || $amount < 0 || $amount > PaymentFieldRules::MAX_MONEY) {
            throw new \InvalidArgumentException('Amount out of range');
        }

        $cents = (int) round($amount * 100);
        $pesos = intdiv($cents, 100);
        $centavos = $cents % 100;

        // Singular only for exactly one peso
        return sprintf('%s %s %02d/100 M.N.', $this->pesosToWords($pesos), $pesos === 1 ? 'PESO' : 'PESOS', $centavos);
    }

    private function pesosToWords(int $pesos): string
    {
        if ($pesos === 0) {
            return 'CERO';
        }

        $millions = intdiv($pesos, 1_000_000);
        $thousands = intdiv($pesos % 1_000_000, 1000);
        $units = $pesos % 1000;

        $parts = [];

        if ($millions > 0) {
            $parts[] = $millions === 1 ? 'UN MILLÓN' : $this->group($millions) . ' MILLONES';
        }

        if ($thousands > 0) {
            // "MIL", never "UN MIL"
            $parts[] = $thousands === 1 ? 'MIL' : $this->group($thousands) . ' MIL';
        }

        if ($units > 0) {
            $parts[] = $this->group($units);
        }

        return implode(' ', $parts);
    }

    /**
     * 1..999 in words. A trailing UNO / VEINTIUNO becomes UN / VEINTIÚN, which is what
     * every caller needs (before MIL, MILLONES and PESOS).
     */
    private function group(int $n): string
    {
        if ($n === 100) {
            return 'CIEN';
        }

        $words = [];

        $hundreds = intdiv($n, 100);
        if ($hundreds > 0) {
            $words[] = self::HUNDREDS[$hundreds];
        }

        $rest = $n % 100;
        if ($rest > 0) {
            $words[] = $rest < 30 ? self::UNITS[$rest] : $this->tensAndUnits($rest);
        }

        $text = implode(' ', $words);

        if (str_ends_with($text, 'VEINTIUNO')) {
            return substr($text, 0, -strlen('VEINTIUNO')) . 'VEINTIÚN';
        }

        if (str_ends_with($text, 'UNO')) {
            return substr($text, 0, -1);
        }

        return $text;
    }

    private function tensAndUnits(int $rest): string
    {
        $tens = intdiv($rest, 10);
        $unit = $rest % 10;

        return $unit === 0 ? self::TENS[$tens] : self::TENS[$tens] . ' Y ' . self::UNITS[$unit];
    }
}
