<?php

namespace App\Tests\Unit;

use App\Service\ProfilePhotoStorageService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ProfilePhotoStorageServiceTest extends TestCase
{
    private string $storageDirectory;
    private ProfilePhotoStorageService $storage;

    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir() . '/gei-profile-photo-' . bin2hex(random_bytes(8));
        $this->storage = new ProfilePhotoStorageService($this->storageDirectory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storageDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $directory) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
        if (is_dir($this->storageDirectory)) {
            rmdir($this->storageDirectory);
        }
    }

    public function testStoresAnImagePrivatelyAndScopesItToItsOwner(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'gei-profile-png-');
        file_put_contents($source, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a+qkAAAAASUVORK5CYII=',
            true,
        ));
        $upload = new UploadedFile($source, 'avatar.png', null, UPLOAD_ERR_OK, true);

        $storedPath = $this->storage->store($upload, 27);

        $this->assertMatchesRegularExpression('/\A27\/[a-f0-9]{32}\.png\z/', $storedPath);
        $this->assertNotFalse($this->storage->resolve($storedPath, 27));
        $this->assertNull($this->storage->resolve($storedPath, 28));
        $this->assertSame('image/png', $this->storage->mimeType($storedPath));

        $this->storage->remove($storedPath, 27);
        $this->assertNull($this->storage->resolve($storedPath, 27));
    }

    public function testRejectsNonImageUploads(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'gei-profile-text-');
        file_put_contents($source, 'not an image');
        $upload = new UploadedFile($source, 'avatar.svg', 'image/svg+xml', UPLOAD_ERR_OK, true);

        try {
            $this->storage->store($upload, 27);
            $this->fail('Non-raster image uploads must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Format non accepté', $exception->getMessage());
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
        }
    }
}
