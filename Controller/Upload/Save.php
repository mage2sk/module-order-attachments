<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Controller\Upload;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Magento\Store\Model\ScopeInterface;
use Panth\OrderAttachments\Model\OrderAttachmentFactory;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as OrderAttachmentResource;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory as AttachmentCollectionFactory;
use Panth\Core\Security\UploadExtensionPolicy;
use Psr\Log\LoggerInterface;

class Save implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const XML_PATH_ENABLED = 'panth_orderattachments/general/enabled';
    private const XML_PATH_ENABLE_ALL_PRODUCTS = 'panth_orderattachments/general/enable_all_products';
    private const XML_PATH_ALLOWED_EXTENSIONS = 'panth_orderattachments/upload/allowed_extensions';
    private const XML_PATH_MAX_FILE_SIZE = 'panth_orderattachments/upload/max_file_size';
    private const UPLOAD_DIR = 'panth/order-attachments';

    private const RATE_LIMIT_MAX_UPLOADS = 20;
    private const RATE_LIMIT_WINDOW_MINUTES = 10;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UploaderFactory $uploaderFactory,
        private readonly Filesystem $filesystem,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomerSession $customerSession,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderAttachmentFactory $attachmentFactory,
        private readonly OrderAttachmentResource $attachmentResource,
        private readonly AttachmentCollectionFactory $attachmentCollectionFactory,
        private readonly RemoteAddress $remoteAddress,
        private readonly LoggerInterface $logger,
        private readonly UploadExtensionPolicy $uploadExtensionPolicy
    ) {
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute(): \Magento\Framework\Controller\Result\Json
    {
        $result = $this->jsonFactory->create();

        try {
            $this->validateModuleEnabled();

            $honeypot = $this->request->getParam('oa_website_url');
            if (!empty($honeypot)) {
                $this->logger->warning('OrderAttachments: Honeypot triggered', [
                    'ip' => $this->remoteAddress->getRemoteAddress(),
                ]);
                throw new LocalizedException(__('Upload validation failed. Please try again.'));
            }

            $this->enforceRateLimit();

            $productId = (int) $this->request->getParam('product_id');

            if (!$productId) {
                throw new LocalizedException(__('Product ID is required.'));
            }

            $this->validateProductAllowsAttachment($productId);

            $allowedExtensions = $this->getAllowedExtensions();
            $maxFileSize = $this->getMaxFileSizeBytes();

            $uploader = $this->uploaderFactory->create(['fileId' => 'file']);
            $uploader->setAllowedExtensions($allowedExtensions);
            $uploader->setAllowRenameFiles(false);
            $uploader->setFilesDispersion(false);

            $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $targetDir = self::UPLOAD_DIR . '/' . $productId;
            $absoluteTargetDir = $mediaDirectory->getAbsolutePath($targetDir);

            if (!$mediaDirectory->isDirectory($targetDir)) {
                $mediaDirectory->create($targetDir);
            }

            $fileInfo = $uploader->validateFile();
            $fileSize = (int) ($fileInfo['size'] ?? 0);

            if ($fileSize > $maxFileSize) {
                $maxMb = $this->scopeConfig->getValue(
                    self::XML_PATH_MAX_FILE_SIZE,
                    ScopeInterface::SCOPE_STORE
                );
                throw new LocalizedException(
                    __('File size exceeds the maximum allowed size of %1 MB.', $maxMb)
                );
            }

            $originalFilename = $fileInfo['name'] ?? 'unknown';

            $this->uploadExtensionPolicy->assertSafeExtension($originalFilename);

            $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $storedFilename = hash('sha256', $originalFilename . microtime(true) . random_int(1000, 9999))
                . '.' . $extension;

            $uploader->setAllowRenameFiles(true);
            $uploadResult = $uploader->save($absoluteTargetDir, $storedFilename);

            if (!$uploadResult || !isset($uploadResult['file'])) {
                throw new LocalizedException(__('File upload failed.'));
            }

            $filePath = $targetDir . '/' . $uploadResult['file'];
            $mimeType = $uploadResult['type'] ?? mime_content_type($absoluteTargetDir . '/' . $uploadResult['file']);

            $customerId = $this->customerSession->isLoggedIn()
                ? (int) $this->customerSession->getCustomerId()
                : null;
            $customerEmail = $this->customerSession->isLoggedIn()
                ? $this->customerSession->getCustomer()->getEmail()
                : ($this->checkoutSession->getQuote()->getCustomerEmail() ?? null);
            $customerNote = (string) $this->request->getParam('customer_note', '');

            $attachment = $this->attachmentFactory->create();
            $attachment->setData([
                'quote_item_id'     => null,
                'product_id'        => $productId,
                'customer_id'       => $customerId,
                'customer_email'    => $customerEmail,
                'original_filename' => $originalFilename,
                'stored_filename'   => $uploadResult['file'],
                'file_path'         => $filePath,
                'file_size'         => (int) $uploadResult['size'],
                'mime_type'         => $mimeType,
                'file_extension'    => strtolower($extension),
                'customer_note'     => $customerNote,
                'status'            => 1,
            ]);

            $this->attachmentResource->save($attachment);

            return $result->setData([
                'success'       => true,
                'attachment_id' => (int) $attachment->getId(),
                'filename'      => $originalFilename,
                'size'          => (int) $uploadResult['size'],
            ]);
        } catch (LocalizedException $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('OrderAttachments upload error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $result->setData([
                'success' => false,
                'message' => __('Sorry, we couldn\'t upload your file. Please try again or contact our support team if the issue persists.'),
            ]);
        }
    }

    private function enforceRateLimit(): void
    {
        $ip = $this->remoteAddress->getRemoteAddress();
        if (!$ip) {
            return;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::RATE_LIMIT_WINDOW_MINUTES . ' minutes'));

        $collection = $this->attachmentCollectionFactory->create();
        $collection->addFieldToFilter('customer_email', ['like' => '%'])
            ->getSelect()
            ->where(
                'created_at >= ?',
                $cutoff
            );

        $customerId = $this->customerSession->isLoggedIn()
            ? (int) $this->customerSession->getCustomerId()
            : null;

        if ($customerId) {
            $collection->addFieldToFilter('customer_id', $customerId);
        } else {
            $quoteId = $this->checkoutSession->getQuoteId();
            if ($quoteId) {
                $email = $this->checkoutSession->getQuote()->getCustomerEmail();
                if ($email) {
                    $collection->addFieldToFilter('customer_email', $email);
                } else {
                    return;
                }
            } else {
                return;
            }
        }

        if ($collection->getSize() >= self::RATE_LIMIT_MAX_UPLOADS) {
            $this->logger->warning('OrderAttachments: Rate limit exceeded', [
                'ip' => $ip,
                'customer_id' => $customerId,
                'count' => $collection->getSize(),
            ]);
            throw new LocalizedException(
                __('Too many uploads. Please wait a few minutes before uploading more files.')
            );
        }
    }

    private function validateModuleEnabled(): void
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            throw new LocalizedException(__('Order attachments feature is disabled.'));
        }
    }

    private function validateProductAllowsAttachment(int $productId): void
    {
        try {
            $product = $this->productRepository->getById($productId);
        } catch (\Exception $e) {
            throw new LocalizedException(__('Product not found.'));
        }

        $allowAttachment = $product->getData('panth_allow_order_attachment');

        $allowed = $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_ALL_PRODUCTS, ScopeInterface::SCOPE_STORE)
            ? ($allowAttachment === null || (bool) $allowAttachment)
            : (bool) $allowAttachment;

        if (!$allowed) {
            throw new LocalizedException(__('This product does not allow file attachments.'));
        }
    }

    private function getAllowedExtensions(): array
    {
        $extensions = (string) $this->scopeConfig->getValue(
            self::XML_PATH_ALLOWED_EXTENSIONS,
            ScopeInterface::SCOPE_STORE
        );

        return array_map('trim', explode(',', $extensions));
    }

    private function getMaxFileSizeBytes(): int
    {
        $maxMb = (int) $this->scopeConfig->getValue(
            self::XML_PATH_MAX_FILE_SIZE,
            ScopeInterface::SCOPE_STORE
        );

        return $maxMb * 1024 * 1024;
    }
}
