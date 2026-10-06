<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Model\Product\Attribute\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

class AllowOrderAttachment extends AbstractSource
{
    public const VALUE_USE_CONFIG = '';
    public const VALUE_YES = 1;
    public const VALUE_NO = 0;

    public function getAllOptions(): array
    {
        if ($this->_options === null) {
            $this->_options = [
                ['value' => self::VALUE_USE_CONFIG, 'label' => __('Use Config Setting')],
                ['value' => self::VALUE_YES, 'label' => __('Yes')],
                ['value' => self::VALUE_NO, 'label' => __('No')],
            ];
        }

        return $this->_options;
    }
}
