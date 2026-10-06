<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use Panth\OrderAttachments\Helper\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values, array $flags = []): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn(string $path) => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn(string $path) => (bool) ($flags[$path] ?? false)
        );
        $context = $this->createStub(Context::class);
        $context->method('getScopeConfig')->willReturn($scopeConfig);

        return new Config($context);
    }

    public function testEnabledFlagIsReadFromItsOwnPath(): void
    {
        $config = $this->config([], ['panth_orderattachments/general/enabled' => true]);

        $this->assertTrue($config->isEnabled());
        $this->assertFalse($config->isEnabledForAllProducts());
    }

    public function testEnableAllProductsFlag(): void
    {
        $config = $this->config([], ['panth_orderattachments/general/enable_all_products' => true]);

        $this->assertFalse($config->isEnabled());
        $this->assertTrue($config->isEnabledForAllProducts());
    }

    public function testStoreIdIsPassedToScopeConfig(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())->method('isSetFlag')
            ->with('panth_orderattachments/general/enabled', ScopeInterface::SCOPE_STORE, 7)
            ->willReturn(true);
        $context = $this->createStub(Context::class);
        $context->method('getScopeConfig')->willReturn($scopeConfig);

        $this->assertTrue((new Config($context))->isEnabled(7));
    }

    public function testAllowedExtensionsAreSplitAndTrimmed(): void
    {
        $config = $this->config(['panth_orderattachments/upload/allowed_extensions' => 'jpg, png ,pdf']);

        $this->assertSame(['jpg', 'png', 'pdf'], $config->getAllowedExtensions());
    }

    public function testAllowedExtensionsAreEmptyWhenUnset(): void
    {
        $this->assertSame([], $this->config([])->getAllowedExtensions());
    }

    public function testNumericValuesAreCastToInt(): void
    {
        $config = $this->config([
            'panth_orderattachments/upload/max_file_size' => '10',
            'panth_orderattachments/upload/max_files_per_item' => '3',
        ]);

        $this->assertSame(10, $config->getMaxFileSize());
        $this->assertSame(3, $config->getMaxFilesPerItem());
    }

    public function testNumericValuesDefaultToZero(): void
    {
        $config = $this->config([]);

        $this->assertSame(0, $config->getMaxFileSize());
        $this->assertSame(0, $config->getMaxFilesPerItem());
    }

    public function testUploadLabel(): void
    {
        $this->assertSame(
            'Upload artwork',
            $this->config(['panth_orderattachments/display/upload_label' => 'Upload artwork'])->getUploadLabel()
        );
        $this->assertSame('', $this->config([])->getUploadLabel());
    }
}
