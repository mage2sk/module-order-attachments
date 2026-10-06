<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Block\Product\View;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Panth\OrderAttachments\Block\Product\View\Upload;
use Panth\OrderAttachments\Helper\Config;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\Collection;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory;
use Panth\OrderAttachments\Test\Unit\Fixture\AttachmentTrait;
use PHPUnit\Framework\TestCase;

class UploadTest extends TestCase
{
    use AttachmentTrait;

    private bool $enabled = true;

    private bool $allProducts = false;

    private ?Product $product = null;

    private string $actionName = 'catalog_product_view';

    private array $params = [];

    private array $currentQuoteItems = [];

    private array $existing = [];

    private string $label = '';

    private function product(mixed $allow, bool $saleable = true, int $id = 3): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('isSaleable')->willReturn($saleable);
        $product->method('getId')->willReturn($id);
        $product->method('getData')->willReturnCallback(
            static fn($key = '') => $key === 'panth_allow_order_attachment' ? $allow : null
        );

        return $product;
    }

    private function block(array $data = []): Upload
    {
        $request = $this->createStub(Http::class);
        $request->method('getFullActionName')->willReturnCallback(fn() => $this->actionName);
        $request->method('getParam')->willReturnCallback(fn($k, $d = null) => $this->params[$k] ?? $d);

        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route = '', $params = []) => 'https://shop/' . $route . (isset($params['id']) ? '/id/' . $params['id'] : '')
        );

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($url);

        $registry = $this->createStub(Registry::class);
        $registry->method('registry')->willReturnCallback(
            fn($key) => $key === 'current_product' ? $this->product : null
        );

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn() => $this->enabled);
        $config->method('isEnabledForAllProducts')->willReturnCallback(fn() => $this->allProducts);
        $config->method('getAllowedExtensions')->willReturn(['jpg', 'pdf']);
        $config->method('getMaxFileSize')->willReturn(5);
        $config->method('getMaxFilesPerItem')->willReturn(4);
        $config->method('getUploadLabel')->willReturnCallback(fn() => $this->label);

        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturnCallback(
            fn() => new \ArrayIterator(array_map(fn($r) => $this->makeAttachment($r), $this->existing))
        );
        $collection->method('getFirstItem')->willReturnCallback(
            fn() => $this->makeAttachment($this->existing[0] ?? [])
        );
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $access = $this->createStub(AttachmentAccess::class);
        $access->method('isCurrentQuoteItem')->willReturnCallback(
            fn(int $id) => in_array($id, $this->currentQuoteItems, true)
        );

        return new Upload($context, $registry, $config, new Json(), $collectionFactory, $data, $access);
    }

    public function testHiddenWhenModuleDisabled(): void
    {
        $this->enabled = false;
        $this->product = $this->product('1');

        $this->assertFalse($this->block()->shouldShow());
    }

    public function testHiddenWithoutProductOrWhenNotSaleable(): void
    {
        $this->assertFalse($this->block()->shouldShow());

        $this->product = $this->product('1', false);
        $this->assertFalse($this->block()->shouldShow());
    }

    public function testProductAttributeOverridesConfig(): void
    {
        $this->allProducts = true;
        $this->product = $this->product('0');
        $this->assertFalse($this->block()->shouldShow());

        $this->allProducts = false;
        $this->product = $this->product(1);
        $this->assertTrue($this->block()->shouldShow());
    }

    public function testUseConfigFallsBackToAllProductsFlag(): void
    {
        $this->product = $this->product(null);
        $this->assertFalse($this->block()->shouldShow());

        $this->allProducts = true;
        $this->product = $this->product('');
        $this->assertTrue($this->block()->shouldShow());
    }

    public function testExplicitProductDataWinsOverRegistry(): void
    {
        $this->product = $this->product('1', true, 3);
        $explicit = $this->product('1', true, 8);

        $block = $this->block(['product' => $explicit]);

        $this->assertSame($explicit, $block->getProduct());
        $this->assertSame(8, $block->getProductId());
    }

    public function testProductIdIsNullWithoutProduct(): void
    {
        $this->assertNull($this->block()->getProductId());
    }

    public function testEditQuoteItemOnlyOnCartConfigureForOwnItem(): void
    {
        $this->params = ['id' => '21'];
        $this->currentQuoteItems = [21];
        $this->assertNull($this->block()->getEditQuoteItemId(), 'wrong action');

        $this->actionName = 'checkout_cart_configure';
        $this->assertSame(21, $this->block()->getEditQuoteItemId());

        $this->currentQuoteItems = [];
        $this->assertNull($this->block()->getEditQuoteItemId(), 'foreign quote item');
    }

    public function testExistingAttachmentsAndNoteForEditedItem(): void
    {
        $this->actionName = 'checkout_cart_configure';
        $this->params = ['id' => 21];
        $this->currentQuoteItems = [21];
        $this->existing = [
            ['attachment_id' => 4, 'original_filename' => 'a.JPG', 'file_size' => '10', 'file_extension' => 'JPG', 'customer_note' => 'Note A'],
            ['attachment_id' => 5, 'original_filename' => 'b.pdf', 'file_size' => '20', 'file_extension' => 'pdf'],
        ];

        $block = $this->block();
        $attachments = $block->getExistingAttachments();

        $this->assertCount(2, $attachments);
        $this->assertSame(4, $attachments[0]['attachmentId']);
        $this->assertSame('uploaded', $attachments[0]['status']);
        $this->assertSame(100, $attachments[0]['progress']);
        $this->assertSame('https://shop/orderattachments/thumbnail/view/id/4', $attachments[0]['thumbnailUrl']);
        $this->assertSame('', $attachments[1]['thumbnailUrl']);
        $this->assertSame(20, $attachments[1]['size']);
        $this->assertSame('Note A', $block->getExistingNote());
    }

    public function testNoExistingDataOutsideEditMode(): void
    {
        $this->existing = [['attachment_id' => 4, 'customer_note' => 'x']];
        $block = $this->block();

        $this->assertSame([], $block->getExistingAttachments());
        $this->assertSame('', $block->getExistingNote());
    }

    public function testUploadConfigJson(): void
    {
        $this->product = $this->product('1', true, 3);
        $this->label = 'Add artwork';

        $config = json_decode($this->block()->getUploadConfig(), true);

        $this->assertSame(3, $config['productId']);
        $this->assertSame('https://shop/orderattachments/upload/save', $config['uploadUrl']);
        $this->assertSame('https://shop/orderattachments/upload/delete', $config['deleteUrl']);
        $this->assertSame('https://shop/orderattachments/upload/listing', $config['listUrl']);
        $this->assertSame(['jpg', 'pdf'], $config['allowedExtensions']);
        $this->assertSame(5, $config['maxFileSize']);
        $this->assertSame(5 * 1024 * 1024, $config['maxFileSizeBytes']);
        $this->assertSame(4, $config['maxFiles']);
        $this->assertSame('Add artwork', $config['uploadLabel']);
        $this->assertSame([], $config['existingAttachments']);
        $this->assertSame('', $config['existingNote']);
    }

    public function testDisplayHelpers(): void
    {
        $block = $this->block();

        $this->assertSame('Attach Files', $block->getUploadLabel());
        $this->assertSame('JPG, PDF', $block->getAllowedExtensions());
        $this->assertSame(5, $block->getMaxFileSize());
        $this->assertSame(4, $block->getMaxFiles());

        $this->label = 'Custom';
        $this->assertSame('Custom', $this->block()->getUploadLabel());
    }
}
