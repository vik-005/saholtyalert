<?php

namespace App\Tests\Unit;

use App\Service\AlertAttachmentStorageService;
use App\Service\CryptoService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AlertAttachmentStorageServiceTest extends TestCase
{
    private string $storageDirectory;
    private AlertAttachmentStorageService $storage;

    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir() . '/gei-attachments-' . bin2hex(random_bytes(8));
        $this->storage = new AlertAttachmentStorageService(
            $this->storageDirectory,
            new CryptoService(CryptoService::generateKey()),
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storageDirectory . '/*/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($this->storageDirectory . '/*') ?: [] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
        if (is_dir($this->storageDirectory)) {
            rmdir($this->storageDirectory);
        }
    }

    public function testStagesAndStoresPdfOutsidePublicPath(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'gei-pdf-');
        file_put_contents($source, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
        $upload = new UploadedFile($source, 'preuve.pdf', null, UPLOAD_ERR_OK, true);

        $staged = $this->storage->stage($upload);
        $encryptedPath = $this->storage->copyToPermanent($staged['token'], $staged['originalName']);
        $storedPath = $this->storage->resolveStoredPath($encryptedPath);

        $this->assertNotFalse($storedPath);
        $this->assertStringContainsString(DIRECTORY_SEPARATOR . 'attachments' . DIRECTORY_SEPARATOR, $storedPath);
        $this->assertSame("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n", file_get_contents($storedPath));
        $this->assertNotSame('attachments/' . basename($storedPath), $encryptedPath);
        $this->assertSame('application/pdf', $staged['mimeType']);

        $this->storage->removeStaged($staged['token']);
        $this->storage->removeStored($encryptedPath);
        $this->assertNull($this->storage->resolveStoredPath($encryptedPath));
    }

    public function testRejectsUnsupportedFileExtension(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'gei-text-');
        file_put_contents($source, 'not an allowed document');
        $upload = new UploadedFile($source, 'preuve.txt', 'text/plain', UPLOAD_ERR_OK, true);

        try {
            $this->storage->stage($upload);
            $this->fail('Unsupported document types must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Format non accepté', $exception->getMessage());
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
        }
    }
}
