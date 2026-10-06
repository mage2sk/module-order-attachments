<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Block\Order\View;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Panth\OrderAttachments\Block\Order\View\Attachments;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\Collection;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttachmentsTest extends TestCase
{
    private ?object $order = null;

    private array $filters = [];

    private ?array $order_by = null;

    private int $size = 0;

    private function block(?ProductRepositoryInterface $repository = null): Attachments
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route = '', $params = []) => 'https://shop/' . $route . '/id/' . ($params['id'] ?? '')
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
        $collection->method('setOrder')->willReturnCallback(function ($f, $d) use (&$collection) {
            $this->order_by = [$f, $d];
            return $collection;
        });
        $collection->method('getSize')->willReturnCallback(fn() => $this->size);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new Attachments(
            $context,
            $registry,
            $factory,
            $repository ?? $this->createStub(ProductRepositoryInterface::class)
        );
    }

    public function testAttachmentsAreFilteredByCurrentOrder(): void
    {
        $this->order = new DataObject(['id' => 15]);
        $this->size = 2;
        $block = $this->block();

        $this->assertTrue($block->hasAttachments());
        $this->assertSame(15, $this->filters['order_id']);
        $this->assertSame(1, $this->filters['status']);
        $this->assertSame(['created_at', 'DESC'], $this->order_by);
    }

    public function testNoOrderReturnsUnfilteredEmptyCollection(): void
    {
        $block = $this->block();

        $this->assertFalse($block->hasAttachments());
        $this->assertSame([], $this->filters);
    }

    public function testUrls(): void
    {
        $block = $this->block();

        $this->assertSame('https://shop/orderattachments/thumbnail/view/id/4', $block->getThumbnailUrl(4));
        $this->assertSame('https://shop/orderattachments/download/index/id/4', $block->getDownloadUrl(4));
    }

    public function testIsImage(): void
    {
        $block = $this->block();

        $this->assertTrue($block->isImage('JPEG'));
        $this->assertTrue($block->isImage('webp'));
        $this->assertFalse($block->isImage('svg'));
        $this->assertFalse($block->isImage('pdf'));
    }

    #[DataProvider('sizes')]
    public function testFormatFileSize(int $bytes, string $expected): void
    {
        $this->assertSame($expected, $this->block()->formatFileSize($bytes));
    }

    public static function sizes(): array
    {
        return [
            'bytes' => [1023, '1023 B'],
            'kilobytes' => [1536, '1.5 KB'],
            'megabytes' => [5 * 1048576, '5.00 MB'],
        ];
    }

    public function testProductNameIsCachedAndFallsBack(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getName')->willReturn('Poster');
        $product->method('getProductUrl')->willReturn('https://shop/poster.html');
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects($this->exactly(3))->method('getById')->willReturnCallback(
            static function (int $id) use ($product) {
                if ($id !== 3) {
                    throw new NoSuchEntityException();
                }
                return $product;
            }
        );
        $block = $this->block($repository);

        $this->assertSame('Poster', $block->getProductName(3));
        $this->assertSame('Poster', $block->getProductName(3));
        $this->assertSame('Product #9', $block->getProductName(9));
        $this->assertSame('https://shop/poster.html', $block->getProductUrl(3));
    }

    public function testProductUrlFallsBackToHash(): void
    {
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException());

        $this->assertSame('#', $this->block($repository)->getProductUrl(3));
    }
}
