<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Controller\Thumbnail;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Panth\OrderAttachments\Model\FileLocator;
use Panth\OrderAttachments\Model\OrderAttachmentFactory;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as AttachmentResource;
use Panth\OrderAttachments\Model\Upload\FileValidator;
use Psr\Log\LoggerInterface;

class View implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawResultFactory,
        private readonly OrderAttachmentFactory $attachmentFactory,
        private readonly AttachmentResource $attachmentResource,
        private readonly LoggerInterface $logger,
        private readonly AttachmentAccess $attachmentAccess,
        private readonly FileLocator $fileLocator,
        private readonly FileValidator $fileValidator
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->rawResultFactory->create();

        try {
            $attachmentId = (int) $this->request->getParam('id');
            if (!$attachmentId) {
                return $this->notFound($result);
            }

            $attachment = $this->attachmentFactory->create();
            $this->attachmentResource->load($attachment, $attachmentId);

            if (!$attachment->getId() || (int) $attachment->getData('status') !== 1) {
                return $this->notFound($result);
            }

            if (!$this->attachmentAccess->canView($attachment)) {
                return $this->notFound($result);
            }

            $mimeType = strtolower((string) $attachment->getData('mime_type'));
            if (!$this->fileValidator->isInlineImageMimeType($mimeType)) {
                return $this->notFound($result);
            }

            $content = $this->fileLocator->readFile((string) $attachment->getData('file_path'));
            if ($content === null) {
                return $this->notFound($result);
            }

            $result->setHttpResponseCode(200);
            $result->setHeader('Content-Type', $mimeType, true);
            $result->setHeader('Content-Length', (string) strlen($content), true);
            $result->setHeader('X-Content-Type-Options', 'nosniff', true);
            $result->setHeader('Content-Disposition', 'inline', true);
            $result->setHeader('Content-Security-Policy', "default-src 'none'; sandbox", true);
            $result->setHeader('Cache-Control', 'private, max-age=3600', true);
            $result->setContents($content);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('OrderAttachments thumbnail error: ' . $e->getMessage());
            return $this->notFound($result);
        }
    }

    private function notFound($result): ResultInterface
    {
        $result->setHttpResponseCode(404);
        $result->setContents('');
        return $result;
    }
}
