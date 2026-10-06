<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Controller\Download;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Panth\OrderAttachments\Model\FileLocator;
use Panth\OrderAttachments\Model\OrderAttachmentFactory;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as OrderAttachmentResource;
use Psr\Log\LoggerInterface;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly FileFactory $fileFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly CustomerSession $customerSession,
        private readonly OrderAttachmentFactory $attachmentFactory,
        private readonly OrderAttachmentResource $attachmentResource,
        private readonly MessageManagerInterface $messageManager,
        private readonly LoggerInterface $logger,
        private readonly AttachmentAccess $attachmentAccess,
        private readonly FileLocator $fileLocator
    ) {
    }

    public function execute(): \Magento\Framework\Controller\ResultInterface|\Magento\Framework\App\ResponseInterface
    {
        try {
            $attachmentId = (int) $this->request->getParam('id');

            if (!$attachmentId) {
                throw new LocalizedException(__('Attachment ID is required.'));
            }

            $attachment = $this->attachmentFactory->create();
            $this->attachmentResource->load($attachment, $attachmentId);

            if (!$attachment->getId() || !(int) $attachment->getData('status')) {
                throw new LocalizedException(__('Attachment not found.'));
            }

            $this->validateAccess($attachment);

            $absolutePath = $this->fileLocator->getAbsolutePath((string) $attachment->getData('file_path'));

            if ($absolutePath === null) {
                throw new LocalizedException(__('The requested file no longer exists.'));
            }

            return $this->fileFactory->create(
                $this->getSafeDownloadName($attachment),
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
            $this->logger->error('OrderAttachments download error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $this->messageManager->addErrorMessage(__('An error occurred while downloading the file.'));
        }

        $redirect = $this->redirectFactory->create();

        return $redirect->setPath('/');
    }

    private function validateAccess(\Panth\OrderAttachments\Model\OrderAttachment $attachment): void
    {
        if ($this->attachmentAccess->canView($attachment)) {
            return;
        }

        if (!$this->customerSession->isLoggedIn()) {
            throw new LocalizedException(__('Please log in to download attachments.'));
        }

        throw new LocalizedException(__('You are not authorized to access this attachment.'));
    }

    private function getSafeDownloadName(\Panth\OrderAttachments\Model\OrderAttachment $attachment): string
    {
        $name = (string) preg_replace(
            '#[\x00-\x1F\x7F"\\\\/;]#',
            '',
            (string) $attachment->getData('original_filename')
        );

        return $name !== '' ? $name : 'attachment';
    }
}
