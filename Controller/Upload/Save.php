<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Controller\Upload;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Magento\Store\Model\ScopeInterface;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Panth\OrderAttachments\Model\FileLocator;
use Panth\OrderAttachments\Model\OrderAttachmentFactory;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as OrderAttachmentResource;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory as AttachmentCollectionFactory;
use Panth\OrderAttachments\Model\Upload\FileValidator;
use Panth\Core\Security\UploadExtensionPolicy;
use Psr\Log\LoggerInterface;

class Save implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const XML_PATH_ENABLED = 'panth_orderattachments/general/enabled';
    private const XML_PATH_ENABLE_ALL_PRODUCTS = 'panth_orderattachments/general/enable_all_products';
    private const XML_PATH_ALLOWED_EXTENSIONS = 'panth_orderattachments/upload/allowed_extensions';
    private const XML_PATH_MAX_FILE_SIZE = 'panth_orderattachments/upload/max_file_size';
    private const UPLOAD_DIR = FileLocator::BASE_PATH;
    private const MAX_NOTE_LENGTH = 500;

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
        private readonly UploadExtensionPolicy $uploadExtensionPolicy,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly FileValidator $fileValidator,
        private readonly AttachmentAccess $attachmentAccess
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
            if (!$this->formKeyValidator->validate($this->request)) {
                throw new LocalizedException(__('Invalid form key. Please refresh the page and try again.'));
            }

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
            $uploader->setAllowCreateFolders(true);
            $uploader->skipDbProcessing(true);

            $fileInfo = $uploader->validateFile();
            $originalFilename = $this->fileValidator->normalizeFilename((string) ($fileInfo['name'] ?? ''));

            $this->uploadExtensionPolicy->assertSafeExtension($originalFilename);
            $extension = $this->fileValidator->assertAllowedExtension($originalFilename, $allowedExtensions);
            $this->fileValidator->assertSize((int) ($fileInfo['size'] ?? 0), $maxFileSize);
            $mimeType = $this->fileValidator->assertSafeContent((string) ($fileInfo['tmp_name'] ?? ''), $extension);

            $storageDirectory = $this->filesystem->getDirectoryWrite(FileLocator::STORAGE_DIRECTORY);
            $targetDir = self::UPLOAD_DIR . '/' . $productId;
            if (!$storageDirectory->isDirectory($targetDir)) {
                $storageDirectory->create($targetDir);
            }
            $absoluteTargetDir = $storageDirectory->getAbsolutePath($targetDir);

            $storedFilename = $this->fileValidator->generateStoredFilename($extension);
            $uploadResult = $uploader->save($absoluteTargetDir, $storedFilename);

            if (!$uploadResult || !isset($uploadResult['file'])) {
                throw new LocalizedException(__('File upload failed.'));
            }

            $filePath = $targetDir . '/' . $uploadResult['file'];

            $customerId = $this->attachmentAccess->getCurrentCustomerId();
            $customerEmail = $customerId !== null
                ? $this->customerSession->getCustomer()->getEmail()
                : ($this->checkoutSession->getQuote()->getCustomerEmail() ?? null);
            $customerNote = mb_substr(trim((string) $this->request->getParam('customer_note', '')), 0, self::MAX_NOTE_LENGTH);

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
                'file_extension'    => $extension,
                'customer_note'     => $customerNote,
                'status'            => 1,
            ]);

            $this->attachmentResource->save($attachment);
            $this->attachmentAccess->rememberUpload((int) $attachment->getId());

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
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::RATE_LIMIT_WINDOW_MINUTES . ' minutes'));

        $collection = $this->attachmentCollectionFactory->create();
        $collection->getSelect()->where('created_at >= ?', $cutoff);

        $customerId = $this->attachmentAccess->getCurrentCustomerId();

        if ($customerId) {
            $collection->addFieldToFilter('customer_id', $customerId);
        } else {
            $sessionIds = $this->attachmentAccess->getSessionUploadIds();
            if (empty($sessionIds)) {
                return;
            }
            $collection->addFieldToFilter('attachment_id', ['in' => $sessionIds]);
        }

        if ($collection->getSize() >= self::RATE_LIMIT_MAX_UPLOADS) {
            $this->logger->warning('OrderAttachments: Rate limit exceeded', [
                'ip' => $this->remoteAddress->getRemoteAddress(),
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

        $allowed = ($allowAttachment === null || $allowAttachment === '')
            ? $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_ALL_PRODUCTS, ScopeInterface::SCOPE_STORE)
            : (bool) (int) $allowAttachment;

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

        return array_values(array_filter(array_map(
            static fn($ext) => strtolower(trim($ext)),
            explode(',', $extensions)
        )));
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
