<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Controller\Adminhtml\Attachment;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Panth_OrderAttachments::attachment_view';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\View\Result\Page
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Panth_OrderAttachments::attachments');
        $resultPage->getConfig()->getTitle()->prepend(__('Order Attachments'));

        return $resultPage;
    }
}
