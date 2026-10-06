<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Controller\Adminhtml\Attachment;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Exception\LocalizedException;
use Panth\OrderAttachments\Model\FileLocator;
use Panth\OrderAttachments\Model\OrderAttachmentFactory;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as OrderAttachmentResource;
use Psr\Log\LoggerInterface;

class Download extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_OrderAttachments::attachment_download';

    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory,
        private readonly FileLocator $fileLocator,
        private readonly OrderAttachmentFactory $attachmentFactory,
        private readonly OrderAttachmentResource $attachmentResource,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\Controller\ResultInterface|\Magento\Framework\App\ResponseInterface
    {
        try {
            $attachmentId = (int) $this->getRequest()->getParam('id');

            if (!$attachmentId) {
                throw new LocalizedException(__('Attachment ID is required.'));
            }

            $attachment = $this->attachmentFactory->create();
            $this->attachmentResource->load($attachment, $attachmentId);

            if (!$attachment->getId()) {
                throw new LocalizedException(__('Attachment not found.'));
            }

            $absolutePath = $this->fileLocator->getAbsolutePath((string) $attachment->getData('file_path'));

            if ($absolutePath === null) {
                throw new LocalizedException(__('The requested file no longer exists.'));
            }

            $originalFilename = (string) preg_replace(
                '#[\x00-\x1F\x7F"\\\\/;]#',
                '',
                (string) $attachment->getData('original_filename')
            );

            return $this->fileFactory->create(
                $originalFilename !== '' ? $originalFilename : 'attachment',
                [
                    'type'  => 'filename',
                    'value' => $absolutePath,
                ],
                DirectoryList::ROOT,
                'application/octet-stream'
            );
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->logger->error('OrderAttachments admin download error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $this->messageManager->addErrorMessage(__('An error occurred while downloading the file.'));
        }

        $resultRedirect = $this->resultRedirectFactory->create();

        return $resultRedirect->setPath('panth_orderattachments/attachment/index');
    }
}
