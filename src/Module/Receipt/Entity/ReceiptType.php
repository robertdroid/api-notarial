<?php

declare(strict_types=1);

namespace App\Module\Receipt\Entity;

enum ReceiptType: string
{
    case Transaction = 'transaction';
    case Payment = 'payment';

    public function title(): string
    {
        return match ($this) {
            self::Transaction => 'RECIBO DE TRÁMITE',
            self::Payment => 'RECIBO DE COBRO',
        };
    }

    /** T-000123 / C-000123. Ids above 999,999 are printed in full (never truncated). */
    public function folio(int $id): string
    {
        return sprintf('%s-%06d', $this === self::Transaction ? 'T' : 'C', $id);
    }

    /** Only [a-z-]: it becomes part of the Content-Disposition file name. */
    public function fileSlug(): string
    {
        return match ($this) {
            self::Transaction => 'recibo-tramite',
            self::Payment => 'recibo-cobro',
        };
    }
}
