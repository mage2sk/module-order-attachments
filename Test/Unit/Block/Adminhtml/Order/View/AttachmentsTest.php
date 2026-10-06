<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Panth\OrderAttachments\Block\Adminhtml\Order\View\Attachments;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\Collection;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory;
use Panth\OrderAttachments\Test\Unit\Fixture\AttachmentTrait;
use PHPUnit\Framework\TestCase;

class AttachmentsTest extends TestCase
{
    use AttachmentTrait;

    private ?object $order = null;

    private array $filters = [];

    private int $size = 0;

    protected function setUp(): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn(string $class) => $this->createStub($class));
        ObjectManager::setInstance($objectManager);
    }

    private function block(?ProductRepositoryInterface $repository = null): Attachments
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route = '', $params = []) => 'https://admin/' . $route . '/id/' . ($params['id'] ?? '')
        );
        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($url);

        $registry = $this->createStub(Registry::class);
        $registry->method('registry')->willReturnCallback(fn($key) => $key === 'current_order' ? $this->order : null);

        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($f, $c) use (&$collection) {
            $this->filters[$f] = $c;
            return $collection;
        });
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getSize')->willReturnCallback(fn() => $this->size);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new Attachments(
            $context,
            $registry,
            $factory,
            $repository ?? $this->createStub(ProductRepositoryInterface::class),
            ['jsonHelper' => $this->createStub(JsonHelper::class), 'directoryHelper' => $this->createStub(DirectoryHelper::class)]
        );
    }

    public function testAttachmentsForCurrentOrder(): void
    {
        $this->order = new DataObject(['id' => 15]);
        $this->size = 1;

        $this->assertTrue($this->block()->hasAttachments());
        $this->assertSame(15, $this->filters['order_id']);
        $this->assertSame(1, $this->filters['status']);
    }

    public function testNoOrderMeansNoAttachments(): void
    {
        $this->assertFalse($this->block()->hasAttachments());
        $this->assertSame([], $this->filters);
    }

    public function testAdminUrls(): void
    {
        $block = $this->block();

        $this->assertSame('https://admin/panth_orderattachments/attachment/download/id/6', $block->getDownloadUrl(6));
        $this->assertSame('https://admin/catalog/product/edit/id/3', $block->getProductEditUrl(3));
    }

    public function testFormatFileSize(): void
    {
        $block = $this->block();

        $this->assertSame('12 B', $block->formatFileSize(12));
        $this->assertSame('2.0 KB', $block->formatFileSize(2048));
        $this->assertSame('1.50 MB', $block->formatFileSize(1572864));
    }

    public function testProductNameFallback(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getName')->willReturn('Poster');
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(static function (int $id) use ($product) {
            if ($id !== 3) {
                throw new NoSuchEntityException();
            }
            return $product;
        });
        $block = $this->block($repository);

        $this->assertSame('Poster', $block->getProductName(3));
        $this->assertSame('Product #4', $block->getProductName(4));
    }

    public function testUploadedByLabel(): void
    {
        $block = $this->block();

        $this->assertSame('jane@example.com', $block->getUploadedBy(
            $this->makeAttachment(['customer_id' => 5, 'customer_email' => 'jane@example.com'])
        ));
        $this->assertSame('Customer #5', $block->getUploadedBy($this->makeAttachment(['customer_id' => 5])));
        $this->assertSame('guest@example.com', $block->getUploadedBy(
            $this->makeAttachment(['customer_email' => 'guest@example.com'])
        ));
        $this->assertSame('Guest', $block->getUploadedBy($this->makeAttachment()));
    }
}
