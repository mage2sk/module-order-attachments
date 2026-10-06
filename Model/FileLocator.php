<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;

class FileLocator
{
    public const BASE_PATH = 'panth/order-attachments';
    public const STORAGE_DIRECTORY = DirectoryList::VAR_DIR;
    public const LEGACY_DIRECTORY = DirectoryList::MEDIA;

    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    public function isValidRelativePath(string $filePath): bool
    {
        if ($filePath === ''
            || strpos($filePath, "\0") !== false
            || strpos($filePath, '\\') !== false
            || strpos($filePath, self::BASE_PATH . '/') !== 0
        ) {
            return false;
        }

        foreach (explode('/', $filePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    public function getReadDirectory(string $filePath): ?ReadInterface
    {
        if (!$this->isValidRelativePath($filePath)) {
            return null;
        }

        foreach ([self::STORAGE_DIRECTORY, self::LEGACY_DIRECTORY] as $directoryCode) {
            $directory = $this->filesystem->getDirectoryRead($directoryCode);
            if ($directory->isFile($filePath)) {
                return $directory;
            }
        }

        return null;
    }

    public function getAbsolutePath(string $filePath): ?string
    {
        $directory = $this->getReadDirectory($filePath);

        return $directory ? $directory->getAbsolutePath($filePath) : null;
    }

    public function readFile(string $filePath): ?string
    {
        $directory = $this->getReadDirectory($filePath);

        return $directory ? $directory->readFile($filePath) : null;
    }
}
