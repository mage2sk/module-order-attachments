<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Panth\OrderAttachments\Model\Product\Attribute\Source\AllowOrderAttachment;

class UseConfigForAllowOrderAttachment implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'panth_allow_order_attachment';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $changes = $this->collectChanges();
        if ($changes['attribute_id'] !== null) {
            $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
            foreach ($changes['attribute'] as $field => $value) {
                $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, $field, $value);
            }

            if (!empty($changes['value_ids'])) {
                foreach (array_chunk($changes['value_ids'], 500) as $chunk) {
                    $connection->delete(
                        $this->moduleDataSetup->getTable('catalog_product_entity_int'),
                        ['value_id IN (?)' => $chunk]
                    );
                }
            }
        }

        $connection->endSetup();

        return $this;
    }

    public function collectChanges(): array
    {
        $result = ['attribute_id' => null, 'attribute' => [], 'value_ids' => [], 'rows' => []];

        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $attribute = $eavSetup->getAttribute(Product::ENTITY, self::ATTRIBUTE_CODE);
        if (!is_array($attribute) || empty($attribute['attribute_id'])) {
            return $result;
        }

        $result['attribute_id'] = (int) $attribute['attribute_id'];

        $target = [
            'frontend_input' => 'select',
            'source_model' => AllowOrderAttachment::class,
            'default_value' => null,
        ];
        foreach ($target as $field => $value) {
            $current = $attribute[$field] ?? null;
            if ($current === '' && $value === null) {
                continue;
            }
            if ($current !== $value) {
                $result['attribute'][$field] = $value;
            }
        }

        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable('catalog_product_entity_int'))
            ->where('attribute_id = ?', $result['attribute_id'])
            ->where('value = ?', AllowOrderAttachment::VALUE_NO);
        $rows = $connection->fetchAll($select);

        $result['rows'] = $rows;
        $result['value_ids'] = array_map(static fn(array $row): int => (int) $row['value_id'], $rows);

        return $result;
    }

    public static function getDependencies(): array
    {
        return [AddAllowOrderAttachmentAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
