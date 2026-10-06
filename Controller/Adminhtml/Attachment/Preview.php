<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Controller\Adminhtml\Attachment;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Panth\OrderAttachments\Model\FileLocator;
use Panth\OrderAttachments\Model\OrderAttachmentFactory;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as OrderAttachmentResource;
use Panth\OrderAttachments\Model\Upload\FileValidator;

class Preview extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_OrderAttachments::attachment_view';

    public function __construct(
        Context $context,
        private readonly RawFactory $rawResultFactory,
        private readonly FileLocator $fileLocator,
        private readonly FileValidator $fileValidator,
        private readonly OrderAttachmentFactory $attachmentFactory,
        private readonly OrderAttachmentResource $attachmentResource
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->rawResultFactory->create();
        $result->setHttpResponseCode(404);
        $result->setContents('');

        $attachmentId = (int) $this->getRequest()->getParam('id');
        if (!$attachmentId) {
            return $result;
        }

        $attachment = $this->attachmentFactory->create();
        $this->attachmentResource->load($attachment, $attachmentId);
        if (!$attachment->getId()) {
            return $result;
        }

        $mimeType = strtolower((string) $attachment->getData('mime_type'));
        if (!$this->fileValidator->isInlineImageMimeType($mimeType)) {
            return $result;
        }

        $content = $this->fileLocator->readFile((string) $attachment->getData('file_path'));
        if ($content === null) {
            return $result;
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
    }
}
