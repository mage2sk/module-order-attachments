<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Panth\OrderAttachments\Model\FileLocator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FileLocatorTest extends TestCase
{
    private const PATH = 'panth/order-attachments/5/abc.png';

    #[DataProvider('validPaths')]
    public function testValidPaths(string $path): void
    {
        $locator = new FileLocator($this->createStub(Filesystem::class));

        $this->assertTrue($locator->isValidRelativePath($path));
    }

    public static function validPaths(): array
    {
        return [
            'product file' => [self::PATH],
            'flat file' => ['panth/order-attachments/abc.pdf'],
        ];
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidPaths(string $path): void
    {
        $locator = new FileLocator($this->createStub(Filesystem::class));

        $this->assertFalse($locator->isValidRelativePath($path));
    }

    public static function invalidPaths(): array
    {
        return [
            'empty' => [''],
            'null byte' => ["panth/order-attachments/a\0.png"],
            'backslash' => ['panth/order-attachments\\a.png'],
            'outside base' => ['catalog/product/a.png'],
            'base prefix only' => ['panth/order-attachmentsX/a.png'],
            'absolute' => ['/panth/order-attachments/a.png'],
            'traversal' => ['panth/order-attachments/../../app/etc/env.php'],
            'dot segment' => ['panth/order-attachments/./a.png'],
            'double slash' => ['panth/order-attachments//a.png'],
            'trailing slash' => ['panth/order-attachments/5/'],
        ];
    }

    private function directory(bool $isFile): ReadInterface
    {
        $dir = $this->createStub(ReadInterface::class);
        $dir->method('isFile')->willReturn($isFile);
        $dir->method('getAbsolutePath')->willReturnCallback(static fn($p = null) => '/abs/' . $p);
        $dir->method('readFile')->willReturn('content');

        return $dir;
    }

    private function filesystem(ReadInterface $var, ReadInterface $media): Filesystem
    {
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturnCallback(
            static fn(string $code) => $code === DirectoryList::VAR_DIR ? $var : $media
        );

        return $filesystem;
    }

    public function testPrefersPrivateStorageDirectory(): void
    {
        $var = $this->directory(true);
        $media = $this->directory(true);

        $this->assertSame($var, (new FileLocator($this->filesystem($var, $media)))->getReadDirectory(self::PATH));
    }

    public function testFallsBackToLegacyMediaDirectory(): void
    {
        $var = $this->directory(false);
        $media = $this->directory(true);
        $locator = new FileLocator($this->filesystem($var, $media));

        $this->assertSame($media, $locator->getReadDirectory(self::PATH));
        $this->assertSame('/abs/' . self::PATH, $locator->getAbsolutePath(self::PATH));
        $this->assertSame('content', $locator->readFile(self::PATH));
    }

    public function testMissingFileReturnsNull(): void
    {
        $locator = new FileLocator($this->filesystem($this->directory(false), $this->directory(false)));

        $this->assertNull($locator->getReadDirectory(self::PATH));
        $this->assertNull($locator->getAbsolutePath(self::PATH));
        $this->assertNull($locator->readFile(self::PATH));
    }

    public function testInvalidPathNeverTouchesFilesystem(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects($this->never())->method('getDirectoryRead');

        $this->assertNull((new FileLocator($filesystem))->readFile('../etc/passwd'));
    }
}
