<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Panth\OrderAttachments\Model\LegacyUploadMigrator;

class MoveLegacyUploadsToPrivateStorage implements DataPatchInterface
{
    public function __construct(
        private readonly LegacyUploadMigrator $migrator
    ) {
    }

    public function apply(): self
    {
        $this->migrator->migrate();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
