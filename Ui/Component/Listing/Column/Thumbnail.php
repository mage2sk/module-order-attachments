<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class Thumbnail extends Column
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    private UrlInterface $urlBuilder;

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (isset($dataSource['data']['items'])) {
            $fieldName = $this->getData('name');

            foreach ($dataSource['data']['items'] as &$item) {
                $ext = strtolower($item['file_extension'] ?? '');
                $attachmentId = (int) ($item['attachment_id'] ?? 0);
                $item[$fieldName . '_alt'] = htmlspecialchars($item['original_filename'] ?? '');

                if (in_array($ext, self::IMAGE_EXTENSIONS, true) && $attachmentId) {
                    $imageUrl = $this->urlBuilder->getUrl(
                        'panth_orderattachments/attachment/preview',
                        ['id' => $attachmentId]
                    );
                    $item[$fieldName . '_src'] = $imageUrl;
                    $item[$fieldName . '_orig_src'] = $imageUrl;
                    $item[$fieldName . '_link'] = $imageUrl;
                } else {
                    $icon = $this->getFileTypeIcon($ext);
                    $item[$fieldName . '_src'] = $icon;
                    $item[$fieldName . '_orig_src'] = $icon;
                    $item[$fieldName . '_link'] = '';
                }
            }
        }
        return $dataSource;
    }

    private function getFileTypeIcon(string $ext): string
    {
        $label = strtoupper(substr((string) preg_replace('/[^a-z0-9]/', '', $ext) ?: 'file', 0, 4));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="48" viewBox="0 0 40 48">'
            . '<path d="M4 1h22l11 11v33a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2z"'
            . ' fill="#ffffff" stroke="#8a837f" stroke-width="2"/>'
            . '<path d="M26 1v11h11" fill="#f1f1f1" stroke="#8a837f" stroke-width="2"/>'
            . '<rect x="2" y="27" width="31" height="14" fill="#514943"/>'
            . '<text x="17.5" y="38" font-family="Arial, sans-serif" font-size="10" font-weight="700"'
            . ' fill="#ffffff" text-anchor="middle">' . $label . '</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
