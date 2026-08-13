<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    private const XML_PATH_ENABLED = 'panth_orderattachments/general/enabled';
    private const XML_PATH_ENABLE_ALL_PRODUCTS = 'panth_orderattachments/general/enable_all_products';
    private const XML_PATH_ALLOWED_EXTENSIONS = 'panth_orderattachments/upload/allowed_extensions';
    private const XML_PATH_MAX_FILE_SIZE = 'panth_orderattachments/upload/max_file_size';
    private const XML_PATH_MAX_FILES_PER_ITEM = 'panth_orderattachments/upload/max_files_per_item';
    private const XML_PATH_UPLOAD_LABEL = 'panth_orderattachments/display/upload_label';
    private const XML_PATH_SHOW_IN_CART = 'panth_orderattachments/display/show_in_cart';
    private const XML_PATH_SHOW_IN_CHECKOUT = 'panth_orderattachments/display/show_in_checkout';

    public function isEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isEnabledForAllProducts(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLE_ALL_PRODUCTS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getAllowedExtensions(int|string|null $storeId = null): array
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_ALLOWED_EXTENSIONS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($value === '') {
            return [];
        }

        return array_map('trim', explode(',', $value));
    }

    public function getMaxFileSize(int|string|null $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_MAX_FILE_SIZE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getMaxFilesPerItem(int|string|null $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_MAX_FILES_PER_ITEM,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getUploadLabel(int|string|null $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_UPLOAD_LABEL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function showInCart(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_IN_CART,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function showInCheckout(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_IN_CHECKOUT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
