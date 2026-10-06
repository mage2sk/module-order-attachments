<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Setup\Patch\Data;

use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Panth\OrderAttachments\Model\Product\Attribute\Source\AllowOrderAttachment;
use Panth\OrderAttachments\Setup\Patch\Data\AddAllowOrderAttachmentAttribute;
use Panth\OrderAttachments\Setup\Patch\Data\UseConfigForAllowOrderAttachment;
use PHPUnit\Framework\TestCase;

class UseConfigForAllowOrderAttachmentTest extends TestCase
{
    private mixed $attribute = false;

    private array $noRows = [];

    private array $attributeUpdates = [];

    private array $deletes = [];

    private function patch(): UseConfigForAllowOrderAttachment
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturnCallback(fn() => $this->noRows);
        $connection->method('delete')->willReturnCallback(function ($table, $where) {
            $this->deletes[] = [$table, $where];
            return count(reset($where));
        });

        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);

        $eavSetup = $this->createStub(EavSetup::class);
        $eavSetup->method('getAttribute')->willReturnCallback(fn() => $this->attribute);
        $eavSetup->method('updateAttribute')->willReturnCallback(function ($entity, $code, $field, $value) use (&$eavSetup) {
            $this->attributeUpdates[$field] = $value;
            return $eavSetup;
        });
        $factory = $this->createStub(EavSetupFactory::class);
        $factory->method('create')->willReturn($eavSetup);

        return new UseConfigForAllowOrderAttachment($setup, $factory);
    }

    public function testMissingAttributeYieldsNoChanges(): void
    {
        $changes = $this->patch()->collectChanges();

        $this->assertNull($changes['attribute_id']);
        $this->assertSame([], $changes['attribute']);
        $this->assertSame([], $changes['value_ids']);
    }

    public function testCollectsAttributeFieldDriftAndExplicitNoValues(): void
    {
        $this->attribute = [
            'attribute_id' => '140',
            'frontend_input' => 'boolean',
            'source_model' => 'Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Boolean',
            'default_value' => '0',
        ];
        $this->noRows = [['value_id' => '11'], ['value_id' => '12']];

        $changes = $this->patch()->collectChanges();

        $this->assertSame(140, $changes['attribute_id']);
        $this->assertSame([
            'frontend_input' => 'select',
            'source_model' => AllowOrderAttachment::class,
            'default_value' => null,
        ], $changes['attribute']);
        $this->assertSame([11, 12], $changes['value_ids']);
        $this->assertCount(2, $changes['rows']);
    }

    public function testAlreadyMigratedAttributeHasNoFieldChanges(): void
    {
        $this->attribute = [
            'attribute_id' => 140,
            'frontend_input' => 'select',
            'source_model' => AllowOrderAttachment::class,
            'default_value' => '',
        ];

        $changes = $this->patch()->collectChanges();

        $this->assertSame([], $changes['attribute']);
        $this->assertSame([], $changes['value_ids']);
    }

    public function testApplyUpdatesAttributeAndDeletesNoValuesInChunks(): void
    {
        $this->attribute = ['attribute_id' => 140, 'frontend_input' => 'boolean', 'source_model' => null, 'default_value' => null];
        $this->noRows = array_map(static fn($i) => ['value_id' => $i], range(1, 1200));

        $patch = $this->patch();
        $this->assertSame($patch, $patch->apply());

        $this->assertSame(['frontend_input' => 'select', 'source_model' => AllowOrderAttachment::class], $this->attributeUpdates);
        $this->assertCount(3, $this->deletes);
        $this->assertSame('catalog_product_entity_int', $this->deletes[0][0]);
        $this->assertCount(500, $this->deletes[0][1]['value_id IN (?)']);
        $this->assertCount(200, $this->deletes[2][1]['value_id IN (?)']);
    }

    public function testApplyWithoutAttributeTouchesNothing(): void
    {
        $this->patch()->apply();

        $this->assertSame([], $this->attributeUpdates);
        $this->assertSame([], $this->deletes);
    }

    public function testPatchMetadata(): void
    {
        $this->assertSame([AddAllowOrderAttachmentAttribute::class], UseConfigForAllowOrderAttachment::getDependencies());
        $this->assertSame([], $this->patch()->getAliases());
    }
}
