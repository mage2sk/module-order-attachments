<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as OrderAttachmentResource;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\Collection;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory;
use Panth\OrderAttachments\Observer\CopyAttachmentsToOrder;
use Panth\OrderAttachments\Test\Unit\Fixture\AttachmentTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CopyAttachmentsToOrderTest extends TestCase
{
    use AttachmentTrait;

    private function observer(?object $order): Observer
    {
        return new Observer(['event' => new Event(['order' => $order])]);
    }

    private function order(array $itemMap, int $orderId = 100): Order
    {
        $items = [];
        foreach ($itemMap as $quoteItemId => $orderItemId) {
            $items[] = new DataObject(['quote_item_id' => $quoteItemId, 'item_id' => $orderItemId]);
        }
        $order = $this->createStub(Order::class);
        $order->method('getAllItems')->willReturn($items);
        $order->method('getId')->willReturn($orderId);

        return $order;
    }

    private function collection(array $attachments, array &$filters): Collection
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function ($field, $condition) use (&$filters, &$collection) {
                $filters[$field] = $condition;
                return $collection;
            }
        );
        $collection->method('getIterator')->willReturn(new \ArrayIterator($attachments));

        return $collection;
    }

    public function testLinksAttachmentsToMatchingOrderItems(): void
    {
        $first = $this->makeAttachment(['attachment_id' => 1, 'quote_item_id' => 10]);
        $second = $this->makeAttachment(['attachment_id' => 2, 'quote_item_id' => 11]);
        $foreign = $this->makeAttachment(['attachment_id' => 3, 'quote_item_id' => 99]);
        $filters = [];

        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($this->collection([$first, $second, $foreign], $filters));

        $resource = $this->createMock(OrderAttachmentResource::class);
        $resource->expects($this->exactly(2))->method('save');

        $observer = new CopyAttachmentsToOrder($factory, $resource, $this->createStub(LoggerInterface::class));
        $observer->execute($this->observer($this->order([10 => 500, 11 => 501, 0 => 502])));

        $this->assertSame(['in' => [10, 11]], $filters['quote_item_id']);
        $this->assertSame(1, $filters['status']);
        $this->assertSame(500, $first->getData('order_item_id'));
        $this->assertSame(100, $first->getData('order_id'));
        $this->assertSame(501, $second->getData('order_item_id'));
        $this->assertNull($foreign->getData('order_id'));
    }

    public function testDoesNothingWithoutOrder(): void
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects($this->never())->method('create');

        (new CopyAttachmentsToOrder(
            $factory,
            $this->createStub(OrderAttachmentResource::class),
            $this->createStub(LoggerInterface::class)
        ))->execute($this->observer(null));
    }

    public function testDoesNothingWhenNoItemHasQuoteItem(): void
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects($this->never())->method('create');

        (new CopyAttachmentsToOrder(
            $factory,
            $this->createStub(OrderAttachmentResource::class),
            $this->createStub(LoggerInterface::class)
        ))->execute($this->observer($this->order([0 => 1])));
    }

    public function testSaveFailureIsLoggedNotThrown(): void
    {
        $filters = [];
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($this->collection(
            [$this->makeAttachment(['attachment_id' => 1, 'quote_item_id' => 10])],
            $filters
        ));
        $resource = $this->createStub(OrderAttachmentResource::class);
        $resource->method('save')->willThrowException(new \RuntimeException('db down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('OrderAttachments: Failed to copy attachments to order.', $this->callback(
                static fn(array $context) => $context['order_id'] === 100 && $context['exception'] instanceof \RuntimeException
            ));

        (new CopyAttachmentsToOrder($factory, $resource, $logger))
            ->execute($this->observer($this->order([10 => 500])));
    }

    public function testFailureBeforeOrderIsResolvedLogsNullOrderId(): void
    {
        $event = $this->createStub(Event::class);
        $event->method('getData')->willThrowException(new \RuntimeException('event broken'));
        $observer = $this->createStub(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('OrderAttachments: Failed to copy attachments to order.', $this->callback(
                static fn(array $context) => array_key_exists('order_id', $context)
                    && $context['order_id'] === null
                    && $context['exception'] instanceof \RuntimeException
            ));

        (new CopyAttachmentsToOrder($factory, $this->createStub(OrderAttachmentResource::class), $logger))
            ->execute($observer);
    }
}
