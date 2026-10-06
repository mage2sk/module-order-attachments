<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Ui\Component\Listing\Column;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Panth\OrderAttachments\Ui\Component\Listing\Column\Actions;
use Panth\OrderAttachments\Ui\Component\Listing\Column\FileSize;
use Panth\OrderAttachments\Ui\Component\Listing\Column\ProductName;
use Panth\OrderAttachments\Ui\Component\Listing\Column\Thumbnail;
use PHPUnit\Framework\TestCase;

class ColumnsTest extends TestCase
{
    private function urlBuilder(): UrlInterface
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn(string $route, array $params = []) => 'https://admin/' . $route . '/id/' . ($params['id'] ?? '')
        );

        return $url;
    }

    private function items(array $items): array
    {
        return ['data' => ['items' => $items]];
    }

    public function testFileSizeFormatsBytesKilobytesAndMegabytes(): void
    {
        $column = new FileSize(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class)
        );

        $result = $column->prepareDataSource($this->items([
            ['file_size' => 500],
            ['file_size' => 2048],
            ['file_size' => 1572864],
            ['other' => 'x'],
        ]));

        $items = $result['data']['items'];
        $this->assertSame('500 B', $items[0]['file_size']);
        $this->assertSame('2 KB', $items[1]['file_size']);
        $this->assertSame('1.5 MB', $items[2]['file_size']);
        $this->assertArrayNotHasKey('file_size', $items[3]);
    }

    public function testFileSizeLeavesDataSourceWithoutItemsUntouched(): void
    {
        $column = new FileSize(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class)
        );

        $this->assertSame(['data' => ['totalRecords' => 0]], $column->prepareDataSource(['data' => ['totalRecords' => 0]]));
    }

    public function testActionsAddDownloadLink(): void
    {
        $column = new Actions(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $this->urlBuilder(),
            [],
            ['name' => 'actions']
        );

        $result = $column->prepareDataSource($this->items([['attachment_id' => 9], ['foo' => 1]]));

        $items = $result['data']['items'];
        $this->assertSame(
            'https://admin/panth_orderattachments/attachment/download/id/9',
            $items[0]['actions']['download']['href']
        );
        $this->assertSame('Download', (string) $items[0]['actions']['download']['label']);
        $this->assertArrayNotHasKey('actions', $items[1]);
    }

    public function testProductNameLinksToEditPageAndEscapesName(): void
    {
        $product = $this->createStub(ProductInterface::class);
        $product->method('getName')->willReturn('Mug <b>XL</b>');
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects($this->once())->method('getById')->with(3)->willReturn($product);

        $column = new ProductName(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $repository,
            $this->urlBuilder(),
            [],
            ['name' => 'product_name']
        );

        $result = $column->prepareDataSource($this->items([
            ['product_id' => 3],
            ['product_id' => 3],
            ['product_id' => 0],
        ]));

        $items = $result['data']['items'];
        $this->assertStringContainsString('href="https://admin/catalog/product/edit/id/3"', $items[0]['product_name']);
        $this->assertStringContainsString('Mug &lt;b&gt;XL&lt;/b&gt;', $items[0]['product_name']);
        $this->assertStringContainsString('(#3)', $items[0]['product_name']);
        $this->assertSame($items[0]['product_name'], $items[1]['product_name'], 'second row served from cache');
        $this->assertArrayNotHasKey('product_name', $items[2]);
    }

    public function testProductNameFallsBackWhenProductIsMissing(): void
    {
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new NoSuchEntityException());

        $column = new ProductName(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $repository,
            $this->urlBuilder(),
            [],
            ['name' => 'product_name']
        );

        $result = $column->prepareDataSource($this->items([['product_id' => 44]]));

        $this->assertStringContainsString('>Product #44</a>', $result['data']['items'][0]['product_name']);
    }

    private function thumbnail(): Thumbnail
    {
        return new Thumbnail(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $this->urlBuilder(),
            [],
            ['name' => 'thumb']
        );
    }

    public function testThumbnailUsesPreviewUrlForImages(): void
    {
        $result = $this->thumbnail()->prepareDataSource($this->items([
            ['attachment_id' => 5, 'file_extension' => 'PNG', 'original_filename' => 'a "b".png'],
        ]));

        $item = $result['data']['items'][0];
        $expected = 'https://admin/panth_orderattachments/attachment/preview/id/5';
        $this->assertSame($expected, $item['thumb_src']);
        $this->assertSame($expected, $item['thumb_orig_src']);
        $this->assertSame($expected, $item['thumb_link']);
        $this->assertSame('a &quot;b&quot;.png', $item['thumb_alt']);
    }

    public function testThumbnailUsesSvgIconForDocuments(): void
    {
        $result = $this->thumbnail()->prepareDataSource($this->items([
            ['attachment_id' => 5, 'file_extension' => 'pdf', 'original_filename' => 'a.pdf'],
            ['file_extension' => 'png'],
        ]));

        $pdf = $result['data']['items'][0];
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $pdf['thumb_src']);
        $svg = base64_decode(substr($pdf['thumb_src'], strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('>PDF</text>', $svg);
        $this->assertSame('', $pdf['thumb_link']);

        $noId = $result['data']['items'][1];
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $noId['thumb_src'], 'image without id gets an icon');
    }

    public function testThumbnailIconLabelIsSanitizedAndTruncated(): void
    {
        $result = $this->thumbnail()->prepareDataSource($this->items([
            ['attachment_id' => 1, 'file_extension' => 'x<s>lsx'],
            ['attachment_id' => 2, 'file_extension' => ''],
        ]));

        $decode = static fn(string $src) => base64_decode(substr($src, strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('>XSLS</text>', $decode($result['data']['items'][0]['thumb_src']));
        $this->assertStringContainsString('>FILE</text>', $decode($result['data']['items'][1]['thumb_src']));
    }
}
