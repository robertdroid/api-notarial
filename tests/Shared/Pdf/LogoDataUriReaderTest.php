<?php

declare(strict_types=1);

namespace Tests\Shared\Pdf;

use App\Module\Notary\Entity\Notary;
use App\Module\Notary\Service\NotaryService;
use App\Shared\Pdf\LogoDataUriReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LogoDataUriReaderTest extends TestCase
{
    private NotaryService&MockObject $notaryService;
    private LogoDataUriReader $reader;

    private string|false $previousErrorLog = false;
    private ?string $logFile = null;
    private ?string $logoFile = null;

    protected function setUp(): void
    {
        $this->notaryService = $this->createMock(NotaryService::class);
        $this->reader = new LogoDataUriReader($this->notaryService);

        $this->logFile = tempnam(sys_get_temp_dir(), 'pdf-log');
        $this->previousErrorLog = ini_set('error_log', (string) $this->logFile);
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }
        foreach ([$this->logFile, $this->logoFile] as $file) {
            if ($file !== null && is_file($file)) {
                unlink($file);
            }
        }
    }

    private function notary(string $logo): Notary
    {
        return new Notary(1, 'Notaría 42', 'Lic. Juan Pérez', 'Querétaro', 'Av. Central 100', '4421234567', 'XAXX010101000', $logo, 1);
    }

    private function logContents(): string
    {
        return (string) file_get_contents((string) $this->logFile);
    }

    private function logoFile(string $bytes): string
    {
        $this->logoFile = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($this->logoFile, $bytes);

        return $this->logoFile;
    }

    public function testNotaryWithoutLogoReturnsNullWithoutQueryingOrLogging(): void
    {
        $this->notaryService->expects($this->never())->method('getLogoInfo');

        $this->assertNull($this->reader->read($this->notary('')));
        $this->assertSame('', $this->logContents());
    }

    public function testReadsAPngAsDataUri(): void
    {
        $path = $this->logoFile('PNGBYTES');
        $this->notaryService->method('getLogoInfo')->with(1)->willReturn(['mimeType' => 'image/png', 'path' => $path]);

        $this->assertSame('data:image/png;base64,' . base64_encode('PNGBYTES'), $this->reader->read($this->notary('assets/logo.png')));
    }

    public function testReadsAJpegAsDataUri(): void
    {
        $path = $this->logoFile('JPEGBYTES');
        $this->notaryService->method('getLogoInfo')->willReturn(['mimeType' => 'image/jpeg', 'path' => $path]);

        $this->assertSame('data:image/jpeg;base64,' . base64_encode('JPEGBYTES'), $this->reader->read($this->notary('assets/logo.jpg')));
    }

    public function testRegisteredLogoWithoutFileIsOmittedAndLogged(): void
    {
        $this->notaryService->method('getLogoInfo')->willReturn(null);

        $this->assertNull($this->reader->read($this->notary('assets/logo.png')));
        $this->assertStringContainsString('[pdf] notary 1 has a logo registered but the file does not exist', $this->logContents());
    }

    public function testUnsupportedTypeIsOmittedAndLogged(): void
    {
        $path = $this->logoFile('<svg></svg>');
        $this->notaryService->method('getLogoInfo')->willReturn(['mimeType' => 'image/svg+xml', 'path' => $path]);

        $this->assertNull($this->reader->read($this->notary('assets/logo.svg')));
        $this->assertStringContainsString('[pdf] notary 1 logo is unreadable or has an unsupported type (image/svg+xml)', $this->logContents());
    }

    public function testUnreadableFileIsOmittedAndLogged(): void
    {
        $this->notaryService->method('getLogoInfo')->willReturn(['mimeType' => 'image/png', 'path' => sys_get_temp_dir() . '/does-not-exist.png']);

        $this->assertNull($this->reader->read($this->notary('assets/logo.png')));
        $this->assertStringContainsString('[pdf] notary 1 logo is unreadable', $this->logContents());
    }

    public function testEmptyFileIsOmittedAndLogged(): void
    {
        $path = $this->logoFile('');
        $this->notaryService->method('getLogoInfo')->willReturn(['mimeType' => 'image/png', 'path' => $path]);

        $this->assertNull($this->reader->read($this->notary('assets/logo.png')));
        $this->assertStringContainsString('[pdf] notary 1 logo is unreadable', $this->logContents());
    }

    public function testLogPrefixIsNeverReceipt(): void
    {
        $this->notaryService->method('getLogoInfo')->willReturn(null);

        $this->reader->read($this->notary('assets/logo.png'));

        $this->assertStringNotContainsString('[receipt]', $this->logContents());
    }
}
