<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Controller\Upload;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Panth\OrderAttachments\Model\OrderAttachmentFactory;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as OrderAttachmentResource;
use Psr\Log\LoggerInterface;

class Delete implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly OrderAttachmentFactory $attachmentFactory,
        private readonly OrderAttachmentResource $attachmentResource,
        private readonly LoggerInterface $logger,
        private readonly FormKeyValidator $formKeyValidator,
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

            $attachmentId = (int) $this->request->getParam('attachment_id');

            if (!$attachmentId) {
                throw new LocalizedException(__('Attachment ID is required.'));
            }

            $attachment = $this->attachmentFactory->create();
            $this->attachmentResource->load($attachment, $attachmentId);

            if (!$attachment->getId()) {
                throw new LocalizedException(__('Attachment not found.'));
            }

            $this->validateOwnership($attachment);

            $attachment->setData('status', 0);
            $this->attachmentResource->save($attachment);

            return $result->setData([
                'success' => true,
                'message' => __('Attachment has been removed.'),
            ]);
        } catch (LocalizedException $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('OrderAttachments delete error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $result->setData([
                'success' => false,
                'message' => __('An error occurred while removing the attachment.'),
            ]);
        }
    }

    private function validateOwnership(\Panth\OrderAttachments\Model\OrderAttachment $attachment): void
    {
        if ($attachment->getData('order_id')) {
            throw new LocalizedException(__('Cannot delete attachments from placed orders.'));
        }

        if (!$this->attachmentAccess->canManage($attachment)) {
            throw new LocalizedException(__('You are not authorized to delete this attachment.'));
        }
    }
}
