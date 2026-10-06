<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\DriverInterface;
use Panth\OrderAttachments\Model\FileLocator;
use Panth\OrderAttachments\Model\LegacyUploadMigrator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LegacyUploadMigratorTest extends TestCase
{
    private const MEDIA_ROOT = '/var/www/pub/media/';

    /** relative path => content, files currently in pub/media */
    private array $mediaFiles = [];

    /** relative path => content, files currently in var/ */
    private array $storageFiles = [];

    private array $existingTables = [];

    private array $rowPaths = [];

    private array $dbFiles = [];

    private array $updates = [];

    private array $deletes = [];

    private array $copyFailures = [];

    private bool $legacyDirRemoved = false;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function migrator(): LegacyUploadMigrator
    {
        $driver = $this->createStub(DriverInterface::class);
        $driver->method('readDirectoryRecursively')->willReturnCallback(function () {
            $paths = [self::MEDIA_ROOT . FileLocator::BASE_PATH . '/3'];
            foreach (array_keys($this->mediaFiles) as $relative) {
                $paths[] = self::MEDIA_ROOT . $relative;
            }
            return $paths;
        });
        $driver->method('isFile')->willReturnCallback(
            fn(string $abs) => isset($this->mediaFiles[substr($abs, strlen(self::MEDIA_ROOT))])
        );
        $driver->method('deleteDirectory')->willReturnCallback(function () {
            $this->legacyDirRemoved = true;
            return true;
        });

        $media = $this->createStub(WriteInterface::class);
        $media->method('getDriver')->willReturn($driver);
        $media->method('isDirectory')->willReturnCallback(fn() => !$this->legacyDirRemoved);
        $media->method('getAbsolutePath')->willReturnCallback(static fn($p = null) => self::MEDIA_ROOT . (string) $p);
        $media->method('readFile')->willReturnCallback(fn($p) => $this->mediaFiles[$p]);
        $media->method('stat')->willReturnCallback(fn($p) => ['size' => strlen($this->mediaFiles[$p])]);
        $media->method('delete')->willReturnCallback(function ($p) {
            unset($this->mediaFiles[$p]);
            return true;
        });
        $media->method('copyFile')->willReturnCallback(function ($src, $dst) {
            if (in_array($src, $this->copyFailures, true)) {
                throw new \RuntimeException('copy failed');
            }
            $this->storageFiles[$dst] = $this->mediaFiles[$src];
            return true;
        });

        $storage = $this->createStub(WriteInterface::class);
        $storage->method('isFile')->willReturnCallback(fn($p) => isset($this->storageFiles[$p]));
        $storage->method('readFile')->willReturnCallback(fn($p) => $this->storageFiles[$p]);
        $storage->method('stat')->willReturnCallback(fn($p) => ['size' => strlen($this->storageFiles[$p])]);
        $storage->method('writeFile')->willReturnCallback(function ($p, $content) {
            $this->storageFiles[$p] = $content;
            return strlen($content);
        });

        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturnCallback(
            static fn($code) => $code === DirectoryList::MEDIA ? $media : $storage
        );

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('isTableExists')->willReturnCallback(fn($t) => in_array($t, $this->existingTables, true));
        $connection->method('fetchPairs')->willReturnCallback(fn() => $this->rowPaths);
        $connection->method('fetchAll')->willReturnCallback(fn() => $this->dbFiles);
        $connection->method('update')->willReturnCallback(function ($table, $bind, $where) {
            $this->updates[] = [$table, $bind, $where];
            return 1;
        });
        $connection->method('delete')->willReturnCallback(function ($table, $where) {
            $this->deletes[] = [$table, $where];
            return 1;
        });
        $connection->method('quoteInto')->willReturnCallback(
            static fn($text, $value) => str_replace('?', "'" . $value . "'", $text)
        );

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new LegacyUploadMigrator(
            $filesystem,
            $resource,
            new FileLocator($this->createStub(Filesystem::class)),
            $this->logger
        );
    }

    public function testNothingToDoOnCleanInstall(): void
    {
        $this->legacyDirRemoved = true;

        $result = $this->migrator()->migrate();

        $this->assertSame(
            ['moved' => 0, 'from_database' => 0, 'skipped' => 0, 'failed' => 0, 'rows_updated' => 0],
            $result
        );
        $this->assertSame([], $this->updates);
        $this->assertSame([], $this->deletes);
    }

    public function testRowPathsAreNormalizedToRelativeStoragePaths(): void
    {
        $this->legacyDirRemoved = true;
        $this->existingTables = ['panth_order_attachment'];
        $this->rowPaths = [
            4 => '/var/www/pub/media/panth/order-attachments/3/a.png',
            5 => 'media/panth/order-attachments/../../app/etc/env.php',
        ];

        $result = $this->migrator()->migrate();

        $this->assertSame(1, $result['rows_updated']);
        $this->assertSame([[
            'panth_order_attachment',
            ['file_path' => 'panth/order-attachments/3/a.png'],
            ['attachment_id = ?' => 4],
        ]], $this->updates);
    }

    public function testMediaFilesAreMovedAndEmptyLegacyDirectoryRemoved(): void
    {
        $this->mediaFiles = [
            'panth/order-attachments/3/a.png' => 'AAA',
            'panth/order-attachments/3/b.pdf' => 'BBBB',
        ];
        $this->storageFiles = ['panth/order-attachments/3/b.pdf' => 'BBBB'];

        $result = $this->migrator()->migrate();

        $this->assertSame(2, $result['moved']);
        $this->assertSame([], $this->mediaFiles);
        $this->assertSame('AAA', $this->storageFiles['panth/order-attachments/3/a.png']);
        $this->assertTrue($this->legacyDirRemoved);
    }

    public function testConflictingFileIsLeftInPlaceAndCopyFailureCounted(): void
    {
        $this->mediaFiles = [
            'panth/order-attachments/3/a.png' => 'legacy',
            'panth/order-attachments/3/c.png' => 'CCC',
            'panth/order-attachments/.htaccess' => 'deny',
        ];
        $this->storageFiles = ['panth/order-attachments/3/a.png' => 'different'];
        $this->copyFailures = ['panth/order-attachments/3/c.png'];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('a different file exists in private storage'),
            ['path' => 'panth/order-attachments/3/a.png']
        );
        $logger->expects($this->once())->method('error')->with(
            'Panth_OrderAttachments: could not move legacy upload',
            ['path' => 'panth/order-attachments/3/c.png', 'error' => 'copy failed']
        );
        $logger->expects($this->once())->method('info');
        $this->logger = $logger;

        $result = $this->migrator()->migrate();

        $this->assertSame(0, $result['moved']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('legacy', $this->mediaFiles['panth/order-attachments/3/a.png']);
        $this->assertArrayHasKey('panth/order-attachments/.htaccess', $this->mediaFiles, '.htaccess is ignored');
        $this->assertFalse($this->legacyDirRemoved, 'directory with leftovers is kept');
    }

    public function testDatabaseStorageCopiesAreWrittenAndPurged(): void
    {
        $this->legacyDirRemoved = true;
        $this->existingTables = ['media_storage_file_storage', 'media_storage_directory_storage'];
        $this->storageFiles = ['panth/order-attachments/3/exists.png' => 'old'];
        $this->dbFiles = [
            ['file_id' => 1, 'filename' => 'new.png', 'directory' => 'panth/order-attachments/3/', 'content' => 'NEW'],
            ['file_id' => 2, 'filename' => 'exists.png', 'directory' => 'panth/order-attachments/3', 'content' => 'DB'],
            ['file_id' => 3, 'filename' => '..', 'directory' => 'panth/order-attachments', 'content' => 'X'],
        ];

        $result = $this->migrator()->migrate();

        $this->assertSame(1, $result['from_database']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('NEW', $this->storageFiles['panth/order-attachments/3/new.png']);
        $this->assertSame('old', $this->storageFiles['panth/order-attachments/3/exists.png'], 'existing file not overwritten');

        $this->assertSame(['media_storage_file_storage', ['file_id = ?' => 1]], $this->deletes[0]);
        $this->assertSame(['media_storage_file_storage', ['file_id = ?' => 2]], $this->deletes[1]);
        $this->assertSame('media_storage_directory_storage', $this->deletes[2][0]);
        $this->assertStringContainsString("path = 'panth/order-attachments'", $this->deletes[2][1]);
        $this->assertStringContainsString("(path = 'panth' AND name = 'order-attachments')", $this->deletes[2][1]);
        $this->assertCount(3, $this->deletes);
    }
}
