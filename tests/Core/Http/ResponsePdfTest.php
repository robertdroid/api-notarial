<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use App\Core\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponsePdfTest extends TestCase
{
    public function testBuildsAnInlinePdfResponse(): void
    {
        $binary = "%PDF-1.7\nbody\n%%EOF";

        $response = Response::pdf($binary, 'recibo-tramite-T-000123.pdf');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($binary, $response->getBody());
        $headers = $response->getHeaders();
        $this->assertSame('application/pdf', $headers['Content-Type']);
        $this->assertSame((string) strlen($binary), $headers['Content-Length']);
        $this->assertSame('inline; filename="recibo-tramite-T-000123.pdf"', $headers['Content-Disposition']);
        $this->assertSame('no-store', $headers['Cache-Control']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    /** @return array<string, array{string}> */
    public static function unsafeNames(): array
    {
        return [
            'empty' => [''],
            'header injection' => ["a.pdf\r\nSet-Cookie: x=1"],
            'quote' => ['a".pdf'],
            'path' => ['../a.pdf'],
            'space' => ['a b.pdf'],
            'trailing newline' => ["a.pdf\n"],
        ];
    }

    #[DataProvider('unsafeNames')]
    public function testRejectsUnsafeFileNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Response::pdf('%PDF-', $name);
    }
}
