<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Setup\Patch\Data;

use Panth\OrderAttachments\Model\LegacyUploadMigrator;
use Panth\OrderAttachments\Setup\Patch\Data\MoveLegacyUploadsToPrivateStorage;
use PHPUnit\Framework\TestCase;

class MoveLegacyUploadsToPrivateStorageTest extends TestCase
{
    public function testApplyRunsTheMigratorOnce(): void
    {
        $migrator = $this->createMock(LegacyUploadMigrator::class);
        $migrator->expects($this->once())->method('migrate')->willReturn(['moved' => 2]);

        $patch = new MoveLegacyUploadsToPrivateStorage($migrator);

        $this->assertSame($patch, $patch->apply());
        $this->assertSame([], MoveLegacyUploadsToPrivateStorage::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }
}
