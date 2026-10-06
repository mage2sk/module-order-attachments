<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Plugin\Cart;

use Magento\Checkout\Controller\Cart\Add as CartAddController;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Core\Helper\Theme as ThemeHelper;
use Panth\OrderAttachments\Helper\Config;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Panth\OrderAttachments\Model\OrderAttachment;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as AttachmentResource;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\Collection;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory;
use Panth\OrderAttachments\Plugin\Cart\LinkAttachmentsAfterAddToCart;
use Panth\OrderAttachments\Test\Unit\Fixture\AttachmentTrait;
use Panth\OrderAttachments\Test\Unit\Fixture\FakeOption;
use Panth\OrderAttachments\Test\Unit\Fixture\FakeQuoteItem;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LinkAttachmentsAfterAddToCartTest extends TestCase
{
    use AttachmentTrait;

    private array $params = [];

    private array $rows = [];

    private array $items = [];

    private bool $hyva = false;

    private int $maxFiles = 0;

    private array $linkedIds = [];

    private array $manageable = [];

    private array $saved = [];

    private array $warnings = [];

    private ?\Throwable $quoteException = null;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function plugin(): LinkAttachmentsAfterAddToCart
    {
        $checkoutSession = $this->createStub(CheckoutSession::class);
        $checkoutSession->method('getQuote')->willReturnCallback(function () {
            if ($this->quoteException) {
                throw $this->quoteException;
            }
            return new DataObject(['all_visible_items' => $this->items, 'store_id' => 1]);
        });

        $resource = $this->createStub(AttachmentResource::class);
        $this->configureLoad($resource, $this->rows);
        $resource->method('save')->willReturnCallback(function (OrderAttachment $attachment) use (&$resource) {
            $this->saved[(int) $attachment->getId()] = $attachment->getData();
            return $resource;
        });

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop/media/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route, $params = []) => 'https://shop/' . $route . '/id/' . ($params['id'] ?? '') . '?a=1&b=2'
        );

        $theme = $this->createStub(ThemeHelper::class);
        $theme->method('isHyva')->willReturnCallback(fn() => $this->hyva);

        $access = $this->createStub(AttachmentAccess::class);
        $access->method('canManage')->willReturnCallback(
            fn(OrderAttachment $a) => in_array((int) $a->getId(), $this->manageable, true)
        );

        $config = $this->createStub(Config::class);
        $config->method('getMaxFilesPerItem')->willReturnCallback(fn() => $this->maxFiles);

        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getAllIds')->willReturnCallback(fn() => $this->linkedIds);
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addWarningMessage')->willReturnCallback(function ($m) use (&$messages) {
            $this->warnings[] = (string) $m;
            return $messages;
        });

        return new LinkAttachmentsAfterAddToCart(
            $checkoutSession,
            $this->attachmentFactoryStub(),
            $resource,
            $storeManager,
            $url,
            $theme,
            $this->logger,
            $access,
            $config,
            $collectionFactory,
            $messages
        );
    }

    private function subject(): CartAddController
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn($k, $d = null) => $this->params[$k] ?? $d);
        $subject = $this->createStub(CartAddController::class);
        $subject->method('getRequest')->willReturn($request);

        return $subject;
    }

    private function item(int $id, int $productId): FakeQuoteItem
    {
        return new FakeQuoteItem(['id' => $id, 'product_id' => $productId]);
    }

    private function row(string $filename, string $ext, int $productId = 3, int $status = 1): array
    {
        return [
            'status' => $status,
            'product_id' => $productId,
            'original_filename' => $filename,
            'file_extension' => $ext,
            'file_path' => 'panth/order-attachments/3/x.' . $ext,
        ];
    }

    public function testReturnsResultUntouchedWithoutAttachmentIds(): void
    {
        $this->quoteException = new \LogicException('quote must not be loaded');
        $this->params = ['product' => 3, 'order_attachment_ids' => 'not-an-array'];

        $this->assertSame('result', $this->plugin()->afterExecute($this->subject(), 'result'));
        $this->assertSame([], $this->saved);
    }

    public function testReturnsResultWithoutProduct(): void
    {
        $this->quoteException = new \LogicException('quote must not be loaded');
        $this->params = ['order_attachment_ids' => [1]];

        $this->assertSame('result', $this->plugin()->afterExecute($this->subject(), 'result'));
    }

    public function testNothingLinkedWhenProductIsNotInCart(): void
    {
        $this->params = ['product' => 3, 'order_attachment_ids' => [1]];
        $this->rows = [1 => $this->row('a.png', 'png')];
        $this->manageable = [1];
        $this->items = [$this->item(50, 99)];

        $this->plugin()->afterExecute($this->subject(), null);

        $this->assertSame([], $this->saved);
        $this->assertSame([], $this->items[0]->addedOptions);
    }

    public function testLinksOnlyValidOwnedAttachmentsAndBuildsLumaSummary(): void
    {
        $this->params = [
            'product' => '3',
            'order_attachment_ids' => ['1', '2', '2', '3', '4', '5'],
            'order_attachment_note' => '  Please <b>print</b> large  ',
        ];
        $this->rows = [
            1 => $this->row('a-very-long-filename-here.png', 'PNG'),
            2 => $this->row('spec.pdf', 'pdf'),
            3 => $this->row('other.pdf', 'pdf', 99),
            4 => $this->row('removed.pdf', 'pdf', 3, 0),
            5 => $this->row('foreign.pdf', 'pdf'),
        ];
        $this->manageable = [1, 2, 3, 4];
        $older = $this->item(40, 3);
        $target = $this->item(50, 3);
        $target->option = FakeOption::withOptions([
            ['label' => 'Gift', 'value' => 'yes'],
            ['label' => 'Attachments', 'value' => 'stale'],
        ]);
        $this->items = [$older, $target];

        $this->plugin()->afterExecute($this->subject(), null);

        $this->assertSame([1, 2], array_keys($this->saved));
        $this->assertSame(50, $this->saved[1]['quote_item_id']);
        $this->assertSame('Please <b>print</b> large', $this->saved[2]['customer_note']);
        $this->assertSame([], $older->addedOptions, 'last matching cart line is the target');

        $options = $target->lastOptions();
        $this->assertSame(['label' => 'Gift', 'value' => 'yes'], $options[0]);
        $this->assertSame('Attachments', $options[1]['label']);
        $this->assertSame(
            '2 files: a-very-long-...png (PNG), spec.pdf (PDF) | Note: Please bprint/b large',
            $options[1]['value']
        );
        $this->assertCount(2, $options);
        $this->assertSame(1, $target->saveCount);
        $this->assertSame([], $this->warnings);
    }

    public function testBuildsHyvaMarkupWithThumbnailsAndEscapedNote(): void
    {
        $this->hyva = true;
        $this->params = [
            'product' => 3,
            'order_attachment_ids' => [1, 2],
            'order_attachment_note' => '<script>x</script>',
        ];
        $this->rows = [1 => $this->row('photo.jpg', 'jpg'), 2 => $this->row('spec.pdf', 'pdf')];
        $this->manageable = [1, 2];
        $target = $this->item(50, 3);
        $this->items = [$target];

        $this->plugin()->afterExecute($this->subject(), null);

        $html = $target->lastOptions()[0]['value'];
        $this->assertStringContainsString('2 files attached', $html);
        $this->assertStringContainsString(
            '<img src="https://shop/orderattachments/thumbnail/view/id/1?a=1&amp;b=2" alt="photo.jpg"',
            $html
        );
        $this->assertStringContainsString('>PDF</span>', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testSingleFileLabel(): void
    {
        $this->hyva = true;
        $this->params = ['product' => 3, 'order_attachment_ids' => [1]];
        $this->rows = [1 => $this->row('photo.jpg', 'jpg')];
        $this->manageable = [1];
        $target = $this->item(50, 3);
        $this->items = [$target];

        $this->plugin()->afterExecute($this->subject(), null);

        $this->assertStringContainsString('1 file attached', $target->lastOptions()[0]['value']);
    }

    public function testMaxFilesPerItemRejectsExtraUploads(): void
    {
        $this->maxFiles = 2;
        $this->linkedIds = ['7', '8'];
        $this->params = ['product' => 3, 'order_attachment_ids' => [7, 9]];
        $this->rows = [7 => $this->row('a.pdf', 'pdf'), 9 => $this->row('b.pdf', 'pdf')];
        $this->manageable = [7, 9];
        $target = $this->item(50, 3);
        $this->items = [$target];

        $this->plugin()->afterExecute($this->subject(), null);

        $this->assertSame([7], array_keys($this->saved), 'already linked file is re-saved, new one rejected');
        $this->assertSame(
            ['Only 2 files can be attached to one cart item. 1 file(s) were not attached.'],
            $this->warnings
        );
        $this->assertSame('1 file: a.pdf (PDF)', $target->lastOptions()[0]['value']);
    }

    public function testErrorsAreLoggedAndResultReturned(): void
    {
        $this->quoteException = new \RuntimeException('session gone');
        $this->params = ['product' => 3, 'order_attachment_ids' => [1]];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('OrderAttachments: Error linking attachments after add-to-cart: session gone');
        $this->logger = $logger;

        $this->assertSame('result', $this->plugin()->afterExecute($this->subject(), 'result'));
    }
}
