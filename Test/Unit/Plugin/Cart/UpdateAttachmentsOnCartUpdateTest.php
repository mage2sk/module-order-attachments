<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Plugin\Cart;

use Magento\Checkout\Controller\Cart\UpdateItemOptions;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Panth\Core\Helper\Theme as ThemeHelper;
use Panth\OrderAttachments\Helper\Config;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Panth\OrderAttachments\Model\OrderAttachment;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as AttachmentResource;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\Collection;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory;
use Panth\OrderAttachments\Plugin\Cart\UpdateAttachmentsOnCartUpdate;
use Panth\OrderAttachments\Test\Unit\Fixture\AttachmentTrait;
use Panth\OrderAttachments\Test\Unit\Fixture\FakeOption;
use Panth\OrderAttachments\Test\Unit\Fixture\FakeQuoteItem;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UpdateAttachmentsOnCartUpdateTest extends TestCase
{
    use AttachmentTrait;

    private array $params = [];

    private array $rows = [];

    private array $items = [];

    /** @var OrderAttachment[] */
    private array $existing = [];

    private array $manageable = [];

    private int $maxFiles = 0;

    private bool $hyva = false;

    private array $saved = [];

    private array $warnings = [];

    private array $collectionFilters = [];

    private ?\Throwable $quoteException = null;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function plugin(): UpdateAttachmentsOnCartUpdate
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
            $this->saved[(int) $attachment->getId()] = $attachment->getData('quote_item_id');
            return $resource;
        });

        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $cond) use (&$collection) {
            $this->collectionFilters[] = [$field, $cond];
            return $collection;
        });
        $collection->method('getIterator')->willReturnCallback(fn() => new \ArrayIterator($this->existing));
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route, $params = []) => 'https://shop/' . $route . '/id/' . ($params['id'] ?? '')
        );

        $theme = $this->createStub(ThemeHelper::class);
        $theme->method('isHyva')->willReturnCallback(fn() => $this->hyva);

        $access = $this->createStub(AttachmentAccess::class);
        $access->method('canManage')->willReturnCallback(
            fn(OrderAttachment $a) => in_array((int) $a->getId(), $this->manageable, true)
        );

        $config = $this->createStub(Config::class);
        $config->method('getMaxFilesPerItem')->willReturnCallback(fn() => $this->maxFiles);

        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addWarningMessage')->willReturnCallback(function ($m) use (&$messages) {
            $this->warnings[] = (string) $m;
            return $messages;
        });

        return new UpdateAttachmentsOnCartUpdate(
            $checkoutSession,
            $this->attachmentFactoryStub(),
            $resource,
            $collectionFactory,
            $url,
            $theme,
            $this->logger,
            $access,
            $config,
            $messages
        );
    }

    private function subject(): UpdateItemOptions
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn($k, $d = null) => $this->params[$k] ?? $d);
        $subject = $this->createStub(UpdateItemOptions::class);
        $subject->method('getRequest')->willReturn($request);

        return $subject;
    }

    private function row(string $filename, string $ext, int $productId = 3): array
    {
        return ['status' => 1, 'product_id' => $productId, 'original_filename' => $filename, 'file_extension' => $ext];
    }

    public function testMissingQuoteItemIdIsIgnored(): void
    {
        $this->quoteException = new \LogicException('quote must not be loaded');
        $this->params = ['product' => 3, 'order_attachment_ids' => [1]];

        $this->assertSame('result', $this->plugin()->afterExecute($this->subject(), 'result'));
    }

    public function testProductNotInCartIsIgnored(): void
    {
        $this->params = ['id' => 40, 'product' => 3, 'order_attachment_ids' => [1]];
        $this->items = [new FakeQuoteItem(['id' => 41, 'product_id' => 4])];

        $this->plugin()->afterExecute($this->subject(), 'result');

        $this->assertSame([], $this->saved);
        $this->assertSame([], $this->collectionFilters);
    }

    public function testClearingAttachmentsUnlinksAndDeletesOnlyAttachmentOption(): void
    {
        $this->params = ['id' => 40, 'product' => 3];
        $item = new FakeQuoteItem(['id' => 41, 'product_id' => 3]);
        $item->option = FakeOption::withOptions([['label' => 'Attachments', 'value' => 'x']]);
        $this->items = [$item];
        $this->existing = [
            $this->makeAttachment(['attachment_id' => 1, 'quote_item_id' => 40]),
            $this->makeAttachment(['attachment_id' => 2, 'quote_item_id' => 40]),
        ];
        $this->manageable = [1];

        $this->plugin()->afterExecute($this->subject(), 'result');

        $this->assertSame([1 => null], $this->saved, 'only manageable attachment is unlinked');
        $this->assertContains(['quote_item_id', 40], $this->collectionFilters);
        $this->assertTrue($item->option->deleted);
        $this->assertSame([], $item->addedOptions);
        $this->assertSame(1, $item->saveCount);
    }

    public function testClearingAttachmentsKeepsOtherOptions(): void
    {
        $this->params = ['id' => 40, 'product' => 3, 'order_attachment_ids' => []];
        $item = new FakeQuoteItem(['id' => 41, 'product_id' => 3]);
        $item->option = FakeOption::withOptions([
            ['label' => 'Engraving', 'value' => 'JD'],
            ['label' => 'Attachment', 'value' => 'legacy'],
        ]);
        $this->items = [$item];

        $this->plugin()->afterExecute($this->subject(), 'result');

        $this->assertFalse($item->option->deleted);
        $this->assertSame([['label' => 'Engraving', 'value' => 'JD']], $item->lastOptions());
    }

    public function testResubmitRelinksToNewItemAndUnlinksDropped(): void
    {
        $this->params = [
            'id' => 40,
            'product' => 3,
            'order_attachment_ids' => ['1', '3', '9'],
            'order_attachment_note' => 'Matte finish',
        ];
        $item = new FakeQuoteItem(['id' => 41, 'product_id' => 3]);
        $item->option = FakeOption::withOptions([
            ['label' => 'Engraving', 'value' => 'JD'],
            ['label' => 'Attachments', 'value' => 'old'],
        ]);
        $this->items = [$item];
        $this->rows = [
            1 => $this->row('keep.pdf', 'pdf'),
            2 => $this->row('drop.pdf', 'pdf'),
            3 => $this->row('new.png', 'png'),
            9 => $this->row('wrong.pdf', 'pdf', 77),
        ];
        $this->existing = [
            $this->makeAttachment(['attachment_id' => 1, 'quote_item_id' => 40]),
            $this->makeAttachment(['attachment_id' => 2, 'quote_item_id' => 40]),
        ];
        $this->manageable = [1, 2, 3, 9];

        $this->plugin()->afterExecute($this->subject(), 'result');

        $this->assertSame([1 => 41, 3 => 41, 2 => null], $this->saved);
        $this->assertContains(['quote_item_id', ['in' => [40, 41]]], $this->collectionFilters);
        $this->assertSame([
            ['label' => 'Engraving', 'value' => 'JD'],
            ['label' => 'Attachments', 'value' => '2 files: keep.pdf (PDF), new.png (PNG) | Note: Matte finish'],
        ], $item->lastOptions());
        $this->assertSame([], $this->warnings);
    }

    public function testMaxFilesLimitRejectsAndUnlinksOverflow(): void
    {
        $this->hyva = true;
        $this->maxFiles = 1;
        $this->params = ['id' => 40, 'product' => 3, 'order_attachment_ids' => [1, 2]];
        $item = new FakeQuoteItem(['id' => 40, 'product_id' => 3]);
        $this->items = [$item];
        $this->rows = [1 => $this->row('a.png', 'png'), 2 => $this->row('b.pdf', 'pdf')];
        $this->existing = [
            $this->makeAttachment(['attachment_id' => 1, 'quote_item_id' => 40]),
            $this->makeAttachment(['attachment_id' => 2, 'quote_item_id' => 40]),
        ];
        $this->manageable = [1, 2];

        $this->plugin()->afterExecute($this->subject(), 'result');

        $this->assertSame([1 => 40, 2 => null], $this->saved);
        $this->assertSame(
            ['Only 1 files can be attached to one cart item. 1 file(s) were not attached.'],
            $this->warnings
        );
        $html = $item->lastOptions()[0]['value'];
        $this->assertStringContainsString('1 file attached', $html);
        $this->assertStringContainsString('https://shop/orderattachments/thumbnail/view/id/1', $html);
    }

    public function testErrorsAreLogged(): void
    {
        $this->quoteException = new \RuntimeException('expired');
        $this->params = ['id' => 40, 'product' => 3];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('OrderAttachments: Error updating attachments on cart update: expired');
        $this->logger = $logger;

        $this->assertSame('result', $this->plugin()->afterExecute($this->subject(), 'result'));
    }
}
