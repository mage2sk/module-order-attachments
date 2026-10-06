<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Controller\Upload;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory;

class Listing implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly AttachmentAccess $attachmentAccess
    ) {
    }

    public function execute()
    {
        $productId = (int) $this->request->getParam('product_id');
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);

        if (!$productId) {
            return $result->setData(['files' => []]);
        }

        $customerId = $this->attachmentAccess->getCurrentCustomerId();
        $sessionIds = $this->attachmentAccess->getSessionUploadIds();

        if ($customerId === null && empty($sessionIds)) {
            return $result->setData(['files' => []]);
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('product_id', $productId);
        $collection->addFieldToFilter('status', 1);
        $collection->addFieldToFilter('order_id', ['null' => true]);
        $collection->addFieldToFilter('quote_item_id', ['null' => true]);
        if ($customerId !== null) {
            $collection->addFieldToFilter('customer_id', $customerId);
        } else {
            $collection->addFieldToFilter('customer_id', ['null' => true]);
            $collection->addFieldToFilter('attachment_id', ['in' => $sessionIds]);
        }
        $collection->setOrder('attachment_id', 'ASC');
        $collection->setPageSize(50);

        $files = [];
        foreach ($collection as $attachment) {
            $files[] = [
                'attachmentId' => (int) $attachment->getId(),
                'name' => (string) $attachment->getData('original_filename'),
                'size' => (int) $attachment->getData('file_size'),
                'extension' => (string) $attachment->getData('file_extension'),
            ];
        }

        return $result->setData(['files' => $files]);
    }
}
