<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Block\Order\View;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Registry;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory;

class Attachments extends Template
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

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

    public function getThumbnailUrl(int $attachmentId): string
    {
        return $this->getUrl('orderattachments/thumbnail/view', ['id' => $attachmentId]);
    }

    public function getDownloadUrl(int $attachmentId): string
    {
        return $this->getUrl('orderattachments/download/index', ['id' => $attachmentId]);
    }

    public function isImage(string $extension): bool
    {
        return in_array(strtolower($extension), self::IMAGE_EXTENSIONS, true);
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

    public function getProductUrl(int $productId): string
    {
        try {
            $product = $this->productRepository->getById($productId);
            return $product->getProductUrl();
        } catch (\Exception $e) {
            return '#';
        }
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
}
