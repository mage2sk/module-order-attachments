<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Model\ResourceModel\OrderAttachment;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\OrderAttachments\Model\OrderAttachment;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as OrderAttachmentResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'attachment_id';

    protected $_eventPrefix = 'panth_order_attachment_collection';

    protected $_eventObject = 'order_attachment_collection';

    protected function _construct(): void
    {
        $this->_init(OrderAttachment::class, OrderAttachmentResource::class);
    }
}
