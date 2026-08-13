<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Block\Product\View;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Panth\OrderAttachments\Helper\Config;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory as AttachmentCollectionFactory;

class Upload extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly Config $config,
        private readonly Json $json,
        private readonly AttachmentCollectionFactory $attachmentCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function shouldShow(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        $product = $this->getProduct();
        if (!$product) {
            return false;
        }

        if (!$product->isSaleable()) {
            return false;
        }

        $allowAttachment = $product->getData('panth_allow_order_attachment');

        if ($this->config->isEnabledForAllProducts()) {
            return $allowAttachment === null || (bool) $allowAttachment;
        }

        return (bool) $allowAttachment;
    }

    public function getProduct()
    {
        if ($this->hasData('product')) {
            return $this->getData('product');
        }
        return $this->registry->registry('current_product');
    }

    public function getProductId(): ?int
    {
        $product = $this->getProduct();
        return $product ? (int) $product->getId() : null;
    }

    public function getEditQuoteItemId(): ?int
    {
        $id = (int) $this->getRequest()->getParam('id');
        return $id > 0 ? $id : null;
    }

    public function getExistingAttachments(): array
    {
        $quoteItemId = $this->getEditQuoteItemId();
        if (!$quoteItemId) {
            return [];
        }

        $collection = $this->attachmentCollectionFactory->create();
        $collection->addFieldToFilter('quote_item_id', $quoteItemId);
        $collection->addFieldToFilter('status', 1);

        $mediaUrl = $this->_storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        $attachments = [];

        foreach ($collection as $attachment) {
            $attachments[] = [
                'attachmentId' => (int) $attachment->getId(),
                'name' => $attachment->getData('original_filename'),
                'size' => (int) $attachment->getData('file_size'),
                'extension' => $attachment->getData('file_extension'),
                'status' => 'uploaded',
                'progress' => 100,
                'errorMessage' => '',
                'thumbnailUrl' => $this->isImageExtension($attachment->getData('file_extension'))
                    ? $mediaUrl . $attachment->getData('file_path')
                    : '',
            ];
        }

        return $attachments;
    }

    public function getExistingNote(): string
    {
        $quoteItemId = $this->getEditQuoteItemId();
        if (!$quoteItemId) {
            return '';
        }

        $collection = $this->attachmentCollectionFactory->create();
        $collection->addFieldToFilter('quote_item_id', $quoteItemId);
        $collection->addFieldToFilter('status', 1);
        $collection->setPageSize(1);

        $first = $collection->getFirstItem();
        return (string) ($first->getData('customer_note') ?? '');
    }

    private function isImageExtension(?string $ext): bool
    {
        return in_array(strtolower($ext ?? ''), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    }

    public function getUploadConfig(): string
    {
        return $this->json->serialize([
            'productId'            => $this->getProductId(),
            'uploadUrl'            => $this->getUploadUrl(),
            'deleteUrl'            => $this->getDeleteUrl(),
            'listUrl'              => $this->getListUrl(),
            'allowedExtensions'    => $this->config->getAllowedExtensions(),
            'maxFileSize'          => $this->config->getMaxFileSize(),
            'maxFileSizeBytes'     => $this->config->getMaxFileSize() * 1024 * 1024,
            'maxFiles'             => $this->config->getMaxFilesPerItem(),
            'uploadLabel'          => $this->config->getUploadLabel(),
            'existingAttachments'  => $this->getExistingAttachments(),
            'existingNote'         => $this->getExistingNote(),
        ]);
    }

    public function getUploadLabel(): string
    {
        return $this->config->getUploadLabel() ?: 'Attach Files';
    }

    public function getAllowedExtensions(): string
    {
        return implode(', ', array_map('strtoupper', $this->config->getAllowedExtensions()));
    }

    public function getMaxFileSize(): int
    {
        return $this->config->getMaxFileSize();
    }

    public function getMaxFiles(): int
    {
        return $this->config->getMaxFilesPerItem();
    }

    public function getUploadUrl(): string
    {
        return $this->getUrl('orderattachments/upload/save');
    }

    public function getDeleteUrl(): string
    {
        return $this->getUrl('orderattachments/upload/delete');
    }

    public function getListUrl(): string
    {
        return $this->getUrl('orderattachments/upload/listing');
    }
}
