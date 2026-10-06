<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Psr\Log\LoggerInterface;

class LegacyUploadMigrator
{
    private const ATTACHMENT_TABLE = 'panth_order_attachment';
    private const DB_FILE_TABLE = 'media_storage_file_storage';
    private const DB_DIRECTORY_TABLE = 'media_storage_directory_storage';
    private const IGNORED_FILES = ['.htaccess'];

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ResourceConnection $resourceConnection,
        private readonly FileLocator $fileLocator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function migrate(): array
    {
        $result = ['moved' => 0, 'from_database' => 0, 'skipped' => 0, 'failed' => 0, 'rows_updated' => 0];

        $storage = $this->filesystem->getDirectoryWrite(FileLocator::STORAGE_DIRECTORY);
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);

        $result['rows_updated'] = $this->normalizeRowPaths();
        $this->moveMediaFiles($media, $storage, $result);
        $this->moveDatabaseCopies($storage, $result);
        $this->removeLegacyDirectory($media);

        $this->logger->info('Panth_OrderAttachments: legacy upload migration finished', $result);

        return $result;
    }

    private function normalizeRowPaths(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::ATTACHMENT_TABLE);
        if (!$connection->isTableExists($table)) {
            return 0;
        }

        $marker = FileLocator::BASE_PATH . '/';
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($table, ['attachment_id', 'file_path'])
                ->where('file_path LIKE ?', '%' . $marker . '%')
                ->where('file_path NOT LIKE ?', $marker . '%')
        );

        $updated = 0;
        foreach ($rows as $attachmentId => $filePath) {
            $relative = substr((string) $filePath, (int) strpos((string) $filePath, $marker));
            if (!$this->fileLocator->isValidRelativePath($relative)) {
                continue;
            }
            $updated += $connection->update(
                $table,
                ['file_path' => $relative],
                ['attachment_id = ?' => (int) $attachmentId]
            );
        }

        return $updated;
    }

    private function moveMediaFiles(WriteInterface $media, WriteInterface $storage, array &$result): void
    {
        foreach ($this->listLegacyFiles($media) as $path) {
            if (!$this->fileLocator->isValidRelativePath($path)) {
                $result['skipped']++;
                continue;
            }

            try {
                if ($storage->isFile($path)) {
                    if ($storage->readFile($path) !== $media->readFile($path)) {
                        $result['skipped']++;
                        $this->logger->warning(
                            'Panth_OrderAttachments: legacy upload left in place, a different file exists in private storage',
                            ['path' => $path]
                        );
                        continue;
                    }
                } else {
                    $media->copyFile($path, $path, $storage);
                }

                if ($storage->isFile($path) && $storage->stat($path)['size'] === $media->stat($path)['size']) {
                    $media->delete($path);
                    $result['moved']++;
                } else {
                    $result['failed']++;
                }
            } catch (\Exception $e) {
                $result['failed']++;
                $this->logger->error(
                    'Panth_OrderAttachments: could not move legacy upload',
                    ['path' => $path, 'error' => $e->getMessage()]
                );
            }
        }
    }

    private function moveDatabaseCopies(WriteInterface $storage, array &$result): void
    {
        $connection = $this->resourceConnection->getConnection();
        $fileTable = $this->resourceConnection->getTableName(self::DB_FILE_TABLE);
        if (!$connection->isTableExists($fileTable)) {
            return;
        }

        $select = $connection->select()
            ->from($fileTable, ['file_id', 'filename', 'directory', 'content'])
            ->where('directory = ? OR directory LIKE ?', FileLocator::BASE_PATH, FileLocator::BASE_PATH . '/%');

        foreach ($connection->fetchAll($select) as $row) {
            $path = trim((string) $row['directory'], '/') . '/' . (string) $row['filename'];
            if (!$this->fileLocator->isValidRelativePath($path)) {
                $result['skipped']++;
                continue;
            }

            try {
                if (!$storage->isFile($path)) {
                    $storage->writeFile($path, (string) $row['content']);
                    $result['from_database']++;
                }
                $connection->delete($fileTable, ['file_id = ?' => (int) $row['file_id']]);
            } catch (\Exception $e) {
                $result['failed']++;
                $this->logger->error(
                    'Panth_OrderAttachments: could not move legacy upload out of database media storage',
                    ['path' => $path, 'error' => $e->getMessage()]
                );
            }
        }

        $directoryTable = $this->resourceConnection->getTableName(self::DB_DIRECTORY_TABLE);
        if ($connection->isTableExists($directoryTable)) {
            [$parent, $name] = explode('/', FileLocator::BASE_PATH, 2);
            $connection->delete(
                $directoryTable,
                $connection->quoteInto('path = ?', FileLocator::BASE_PATH)
                . ' OR ' . $connection->quoteInto('path LIKE ?', FileLocator::BASE_PATH . '/%')
                . ' OR (' . $connection->quoteInto('path = ?', $parent)
                . ' AND ' . $connection->quoteInto('name = ?', $name) . ')'
            );
        }
    }

    private function listLegacyFiles(WriteInterface $media): array
    {
        if (!$media->isDirectory(FileLocator::BASE_PATH)) {
            return [];
        }

        $driver = $media->getDriver();
        $root = rtrim((string) $media->getAbsolutePath(), '/') . '/';
        $files = [];
        foreach ($driver->readDirectoryRecursively($root . FileLocator::BASE_PATH) as $absolutePath) {
            $absolutePath = (string) $absolutePath;
            if (strpos($absolutePath, $root) !== 0
                || in_array(basename($absolutePath), self::IGNORED_FILES, true)
                || !$driver->isFile($absolutePath)
            ) {
                continue;
            }
            $files[] = substr($absolutePath, strlen($root));
        }

        return $files;
    }

    private function removeLegacyDirectory(WriteInterface $media): void
    {
        if (!$media->isDirectory(FileLocator::BASE_PATH) || $this->listLegacyFiles($media) !== []) {
            return;
        }

        try {
            $media->getDriver()->deleteDirectory($media->getAbsolutePath(FileLocator::BASE_PATH));
        } catch (\Exception $e) {
            $this->logger->warning(
                'Panth_OrderAttachments: could not remove the empty legacy upload directory',
                ['error' => $e->getMessage()]
            );
        }
    }
}
