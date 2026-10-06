<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as OrderAttachmentResource;

class OrderAttachment extends AbstractModel
{
    protected $_eventPrefix = 'panth_order_attachment';

    protected $_eventObject = 'order_attachment';

    protected function _construct(): void
    {
        $this->_init(OrderAttachmentResource::class);
    }

    public function getAttachmentId(): ?int
    {
        $id = $this->getData('attachment_id');
        return $id !== null ? (int) $id : null;
    }

    public function getQuoteItemId(): ?int
    {
        $id = $this->getData('quote_item_id');
        return $id !== null ? (int) $id : null;
    }

    public function setQuoteItemId(?int $quoteItemId): self
    {
        return $this->setData('quote_item_id', $quoteItemId);
    }

    public function getOrderItemId(): ?int
    {
        $id = $this->getData('order_item_id');
        return $id !== null ? (int) $id : null;
    }

    public function setOrderItemId(?int $orderItemId): self
    {
        return $this->setData('order_item_id', $orderItemId);
    }

    public function getOrderId(): ?int
    {
        $id = $this->getData('order_id');
        return $id !== null ? (int) $id : null;
    }

    public function setOrderId(?int $orderId): self
    {
        return $this->setData('order_id', $orderId);
    }

    public function getProductId(): int
    {
        return (int) $this->getData('product_id');
    }

    public function setProductId(int $productId): self
    {
        return $this->setData('product_id', $productId);
    }

    public function getCustomerId(): ?int
    {
        $id = $this->getData('customer_id');
        return $id !== null ? (int) $id : null;
    }

    public function setCustomerId(?int $customerId): self
    {
        return $this->setData('customer_id', $customerId);
    }

    public function getCustomerEmail(): ?string
    {
        return $this->getData('customer_email');
    }

    public function setCustomerEmail(?string $customerEmail): self
    {
        return $this->setData('customer_email', $customerEmail);
    }

    public function getOriginalFilename(): string
    {
        return (string) $this->getData('original_filename');
    }

    public function setOriginalFilename(string $originalFilename): self
    {
        return $this->setData('original_filename', $originalFilename);
    }

    public function getStoredFilename(): string
    {
        return (string) $this->getData('stored_filename');
    }

    public function setStoredFilename(string $storedFilename): self
    {
        return $this->setData('stored_filename', $storedFilename);
    }

    public function getFilePath(): string
    {
        return (string) $this->getData('file_path');
    }

    public function setFilePath(string $filePath): self
    {
        return $this->setData('file_path', $filePath);
    }

    public function getFileSize(): int
    {
        return (int) $this->getData('file_size');
    }

    public function setFileSize(int $fileSize): self
    {
        return $this->setData('file_size', $fileSize);
    }

    public function getMimeType(): ?string
    {
        return $this->getData('mime_type');
    }

    public function setMimeType(?string $mimeType): self
    {
        return $this->setData('mime_type', $mimeType);
    }

    public function getFileExtension(): ?string
    {
        return $this->getData('file_extension');
    }

    public function setFileExtension(?string $fileExtension): self
    {
        return $this->setData('file_extension', $fileExtension);
    }

    public function getCustomerNote(): ?string
    {
        return $this->getData('customer_note');
    }

    public function setCustomerNote(?string $customerNote): self
    {
        return $this->setData('customer_note', $customerNote);
    }

    public function getStatus(): int
    {
        return (int) $this->getData('status');
    }

    public function setStatus(int $status): self
    {
        return $this->setData('status', $status);
    }

    public function getCreatedAt(): ?string
    {
        return $this->getData('created_at');
    }

    public function getUpdatedAt(): ?string
    {
        return $this->getData('updated_at');
    }
}
