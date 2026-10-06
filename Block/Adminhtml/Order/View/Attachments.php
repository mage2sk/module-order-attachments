<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Registry;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory;

class Attachments extends Template
{
    private array $productNameCache = [];

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getOrder()
    {
        return $this->registry->registry('current_order');
    }

    public function getAttachments()
    {
        $order = $this->getOrder();
        if (!$order) {
            return $this->collectionFactory->create();
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('order_id', $order->getId());
        $collection->addFieldToFilter('status', 1);
        $collection->setOrder('created_at', 'DESC');

        return $collection;
    }

    public function hasAttachments(): bool
    {
        return $this->getAttachments()->getSize() > 0;
    }

    public function getDownloadUrl(int $attachmentId): string
    {
        return $this->getUrl('panth_orderattachments/attachment/download', [
            'id' => $attachmentId
        ]);
    }

    public function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    public function getProductName(int $productId): string
    {
        if (!isset($this->productNameCache[$productId])) {
            try {
                $product = $this->productRepository->getById($productId);
                $this->productNameCache[$productId] = $product->getName();
            } catch (\Exception $e) {
                $this->productNameCache[$productId] = 'Product #' . $productId;
            }
        }
        return $this->productNameCache[$productId];
    }

    public function getProductEditUrl(int $productId): string
    {
        return $this->getUrl('catalog/product/edit', ['id' => $productId]);
    }

    public function getUploadedBy(\Panth\OrderAttachments\Model\OrderAttachment $attachment): string
    {
        $email = $attachment->getCustomerEmail();
        $customerId = $attachment->getCustomerId();

        if ($customerId) {
            return $email ?: __('Customer #%1', $customerId)->render();
        }

        return $email ?: __('Guest')->render();
    }
}
