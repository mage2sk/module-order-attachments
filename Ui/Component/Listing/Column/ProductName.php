<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Ui\Component\Listing\Column;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class ProductName extends Column
{
    private ProductRepositoryInterface $productRepository;
    private UrlInterface $urlBuilder;
    private array $cache = [];

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        ProductRepositoryInterface $productRepository,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->productRepository = $productRepository;
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                $productId = (int) ($item['product_id'] ?? 0);
                if ($productId) {
                    if (!isset($this->cache[$productId])) {
                        try {
                            $product = $this->productRepository->getById($productId);
                            $this->cache[$productId] = $product->getName();
                        } catch (\Exception $e) {
                            $this->cache[$productId] = 'Product #' . $productId;
                        }
                    }
                    $editUrl = $this->urlBuilder->getUrl('catalog/product/edit', ['id' => $productId]);
                    $name = $this->cache[$productId];
                    $item[$this->getData('name')] = '<a href="' . $editUrl . '" target="_blank">' . htmlspecialchars($name) . '</a> '
                        . '<span style="color:#666;white-space:nowrap;">(#' . $productId . ')</span>';
                }
            }
        }
        return $dataSource;
    }
}
