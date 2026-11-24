<?php

declare(strict_types=1);

namespace Tests\Module\Receipt\Entity;

use App\Module\Receipt\Entity\ReceiptType;
use PHPUnit\Framework\TestCase;

final class ReceiptTypeTest extends TestCase
{
    public function testFolioHasTypePrefixAndSixDigits(): void
    {
        $this->assertSame('T-000123', ReceiptType::Transaction->folio(123));
        $this->assertSame('C-000123', ReceiptType::Payment->folio(123));
        $this->assertSame('T-000001', ReceiptType::Transaction->folio(1));
    }

    public function testFolioIsNeverTruncated(): void
    {
        $this->assertSame('C-1234567', ReceiptType::Payment->folio(1234567));
    }

    public function testTitles(): void
    {
        $this->assertSame('RECIBO DE TRÁMITE', ReceiptType::Transaction->title());
        $this->assertSame('RECIBO DE COBRO', ReceiptType::Payment->title());
    }

    public function testFileSlugIsSafeForHeaders(): void
    {
        foreach (ReceiptType::cases() as $type) {
            $this->assertMatchesRegularExpression('/^[a-z-]+$/', $type->fileSlug());
        }
    }
}
