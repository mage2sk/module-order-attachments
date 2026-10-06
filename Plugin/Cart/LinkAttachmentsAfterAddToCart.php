<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Plugin\Cart;

use Magento\Checkout\Controller\Cart\Add as CartAddController;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Core\Helper\Theme as ThemeHelper;
use Panth\OrderAttachments\Helper\Config;
use Panth\OrderAttachments\Model\OrderAttachmentFactory;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as AttachmentResource;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory as AttachmentCollectionFactory;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Psr\Log\LoggerInterface;

class LinkAttachmentsAfterAddToCart
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderAttachmentFactory $attachmentFactory,
        private readonly AttachmentResource $attachmentResource,
        private readonly StoreManagerInterface $storeManager,
        private readonly \Magento\Framework\UrlInterface $urlBuilder,
        private readonly ThemeHelper $themeHelper,
        private readonly LoggerInterface $logger,
        private readonly AttachmentAccess $attachmentAccess,
        private readonly Config $config,
        private readonly AttachmentCollectionFactory $attachmentCollectionFactory,
        private readonly MessageManager $messageManager
    ) {}

    public function afterExecute(CartAddController $subject, $result)
    {
        try {
            $request = $subject->getRequest();
            $attachmentIds = $request->getParam('order_attachment_ids');

            if (empty($attachmentIds) || !is_array($attachmentIds)) {
                return $result;
            }

            $productId = (int) $request->getParam('product');
            if (!$productId) {
                return $result;
            }

            $customerNote = trim((string) $request->getParam('order_attachment_note', ''));

            $quote = $this->checkoutSession->getQuote();
            $targetItem = null;

            foreach ($quote->getAllVisibleItems() as $item) {
                if ((int) $item->getProductId() === $productId) {
                    $targetItem = $item;
                }
            }

            if (!$targetItem || !$targetItem->getId()) {
                return $result;
            }

            $mediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
            $attachmentData = [];
            $targetItemId = (int) $targetItem->getId();
            $maxFiles = $this->config->getMaxFilesPerItem($quote->getStoreId());
            $linkedIds = $this->getLinkedAttachmentIds($targetItemId);
            $rejected = 0;

            foreach (array_unique(array_map('intval', $attachmentIds)) as $attachmentId) {
                $attachment = $this->attachmentFactory->create();
                $this->attachmentResource->load($attachment, $attachmentId);

                if ($attachment->getId() && (int) $attachment->getData('status') === 1
                    && (int) $attachment->getData('product_id') === $productId
                    && $this->attachmentAccess->canManage($attachment)
                ) {
                    if (!in_array($attachmentId, $linkedIds, true)) {
                        if ($maxFiles > 0 && count($linkedIds) >= $maxFiles) {
                            $rejected++;
                            continue;
                        }
                        $linkedIds[] = $attachmentId;
                    }
                    $attachment->setData('quote_item_id', $targetItemId);
                    if ($customerNote !== '') {
                        $attachment->setData('customer_note', $customerNote);
                    }
                    $this->attachmentResource->save($attachment);
                    $attachmentData[] = [
                        'id' => (int) $attachment->getId(),
                        'filename' => $attachment->getData('original_filename'),
                        'file_path' => $attachment->getData('file_path'),
                        'extension' => strtolower($attachment->getData('file_extension') ?? ''),
                    ];
                }
            }

            if (!empty($attachmentData)) {
                if ($this->themeHelper->isHyva()) {
                    $html = $this->buildHyvaHtml($attachmentData, $customerNote, $mediaUrl);
                } else {
                    $html = $this->buildLumaHtml($attachmentData, $customerNote);
                }

                $additionalOptions = [
                    [
                        'label' => 'Attachments',
                        'value' => $html,
                    ],
                ];

                $existingOption = $targetItem->getOptionByCode('additional_options');
                if ($existingOption) {
                    $existing = json_decode($existingOption->getValue(), true) ?: [];
                    $existing = array_filter($existing, fn($opt) => ($opt['label'] ?? '') !== 'Attachments');
                    $additionalOptions = array_merge(array_values($existing), $additionalOptions);
                }

                $targetItem->addOption([
                    'code' => 'additional_options',
                    'value' => json_encode($additionalOptions),
                    'product_id' => $targetItem->getProductId(),
                ]);
                $targetItem->save();
            }
            if ($rejected > 0) {
                $this->messageManager->addWarningMessage(
                    __(
                        'Only %1 files can be attached to one cart item. %2 file(s) were not attached.',
                        $maxFiles,
                        $rejected
                    )
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('OrderAttachments: Error linking attachments after add-to-cart: ' . $e->getMessage());
        }

        return $result;
    }

    private function getLinkedAttachmentIds(int $quoteItemId): array
    {
        $collection = $this->attachmentCollectionFactory->create();
        $collection->addFieldToFilter('quote_item_id', $quoteItemId);
        $collection->addFieldToFilter('status', 1);

        return array_map('intval', $collection->getAllIds());
    }

    private function buildHyvaHtml(array $attachments, string $note, string $mediaUrl): string
    {
        $count = count($attachments);
        $fileLabel = $count === 1 ? '1 file attached' : $count . ' files attached';

        $html = '<span style="display:block;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;'
            . 'padding:10px;margin:6px 0;max-width:100%;">';

        $html .= '<span style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">'
            . '<span style="color:#0d9488;font-size:14px;">&#128206;</span>'
            . '<span style="font-size:12px;font-weight:600;color:#374151;">' . $fileLabel . '</span>'
            . '</span>';

        $html .= '<span style="display:flex;flex-wrap:wrap;gap:6px;">';
        foreach ($attachments as $data) {
            $ext = $data['extension'];
            $isImage = in_array($ext, self::IMAGE_EXTENSIONS, true);
            $truncated = $this->truncateFilename($data['filename']);
            $attachmentId = $data['id'] ?? 0;
            $alt = htmlspecialchars($truncated);
            $thumbnailUrl = htmlspecialchars(
                $this->urlBuilder->getUrl('orderattachments/thumbnail/view', ['id' => $attachmentId])
            );

            if ($isImage && $attachmentId) {
                $html .= '<a href="' . $thumbnailUrl . '" data-oa-lightbox="1" '
                    . 'style="display:block;flex-shrink:0;text-decoration:none;position:relative;'
                    . 'width:80px;height:80px;border-radius:8px;overflow:hidden;'
                    . 'box-shadow:0 1px 3px rgba(0,0,0,0.1);cursor:pointer;">'
                    . '<img src="' . $thumbnailUrl . '" alt="' . $alt . '" '
                    . 'style="display:block;width:80px;height:80px;object-fit:cover;" />'
                    . '<span style="position:absolute;bottom:0;left:0;right:0;padding:3px 5px;'
                    . 'background:linear-gradient(transparent,rgba(0,0,0,0.6));color:#fff;font-size:9px;'
                    . 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'
                    . $alt . '</span></a>';
            } else {
                $extUpper = strtoupper(htmlspecialchars($ext));
                $html .= '<span style="display:flex;flex-direction:column;align-items:center;justify-content:center;'
                    . 'width:80px;height:80px;background:#fff;border-radius:8px;border:1px solid #e5e7eb;'
                    . 'box-shadow:0 1px 3px rgba(0,0,0,0.05);">'
                    . '<span style="font-size:18px;font-weight:800;color:#9ca3af;">' . $extUpper . '</span>'
                    . '<span style="font-size:9px;color:#9ca3af;margin-top:3px;max-width:70px;'
                    . 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
                    . htmlspecialchars($truncated) . '</span></span>';
            }
        }
        $html .= '</span>';

        if ($note !== '') {
            $html .= '<span style="display:block;margin-top:8px;padding-top:8px;border-top:1px solid #e5e7eb;">'
                . '<span style="display:flex;align-items:flex-start;gap:6px;">'
                . '<span style="color:#9ca3af;font-size:12px;flex-shrink:0;">&#9998;</span>'
                . '<span style="font-size:12px;color:#6b7280;line-height:1.4;word-break:break-word;">'
                . htmlspecialchars($note) . '</span></span></span>';
        }

        $html .= '</span>';
        return $html;
    }

    private function buildLumaHtml(array $attachments, string $note): string
    {
        $count = count($attachments);
        $fileNames = [];

        foreach ($attachments as $data) {
            $truncated = $this->truncateFilename($data['filename']);
            $ext = strtoupper($data['extension']);
            $fileNames[] = str_replace(['<', '>'], '', $truncated) . ' (' . $ext . ')';
        }

        $text = $count . ($count === 1 ? ' file' : ' files') . ': ' . implode(', ', $fileNames);

        if ($note !== '') {
            $text .= ' | Note: ' . str_replace(['<', '>'], '', $note);
        }

        return $text;
    }

    private function truncateFilename(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);

        if (mb_strlen($name) > 15) {
            $name = mb_substr($name, 0, 12) . '..';
        }

        return $name . '.' . $extension;
    }
}
