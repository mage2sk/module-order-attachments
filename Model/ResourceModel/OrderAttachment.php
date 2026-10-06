<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class OrderAttachment extends AbstractDb
{
    protected $_eventPrefix = 'panth_order_attachment_resource';

    protected function _construct(): void
    {
        $this->_init('panth_order_attachment', 'attachment_id');
    }
}
