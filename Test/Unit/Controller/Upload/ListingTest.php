<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Controller\Upload;

use Magento\Framework\App\RequestInterface;
use Panth\OrderAttachments\Controller\Upload\Listing;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\Collection;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory;
use Panth\OrderAttachments\Test\Unit\Controller\JsonResultTrait;
use Panth\OrderAttachments\Test\Unit\Fixture\AttachmentTrait;
use PHPUnit\Framework\TestCase;

class ListingTest extends TestCase
{
    use AttachmentTrait;
    use JsonResultTrait;

    private array $filters = [];

    private function controller(array $params, ?int $customerId, array $sessionIds, array $rows = [], bool $expectQuery = true): Listing
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($k, $d = null) => $params[$k] ?? $d);

        $access = $this->createStub(AttachmentAccess::class);
        $access->method('getCurrentCustomerId')->willReturn($customerId);
        $access->method('getSessionUploadIds')->willReturn($sessionIds);

        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $cond) use (&$collection) {
            $this->filters[$field][] = $cond;
            return $collection;
        });
        $collection->method('getIterator')->willReturn(new \ArrayIterator(
            array_map(fn(array $row) => $this->makeAttachment($row), $rows)
        ));

        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects($expectQuery ? $this->once() : $this->never())->method('create')->willReturn($collection);

        return new Listing($request, $this->jsonFactory(), $factory, $access);
    }

    public function testMissingProductReturnsEmptyListWithoutQuery(): void
    {
        $this->controller([], 5, [], [], false)->execute();

        $this->assertSame(['files' => []], $this->jsonData);
        $this->assertSame('no-store, no-cache, must-revalidate', $this->headers['Cache-Control']);
    }

    public function testAnonymousVisitorWithoutUploadsGetsNothing(): void
    {
        $this->controller(['product_id' => 3], null, [], [], false)->execute();

        $this->assertSame(['files' => []], $this->jsonData);
    }

    public function testCustomerListIsScopedToCustomer(): void
    {
        $this->controller(['product_id' => '3'], 12, [], [
            ['attachment_id' => 4, 'original_filename' => 'a.png', 'file_size' => '100', 'file_extension' => 'png'],
        ])->execute();

        $this->assertSame([
            ['attachmentId' => 4, 'name' => 'a.png', 'size' => 100, 'extension' => 'png'],
        ], $this->jsonData['files']);
        $this->assertSame([3], $this->filters['product_id']);
        $this->assertSame([12], $this->filters['customer_id']);
        $this->assertSame([['null' => true]], $this->filters['order_id']);
        $this->assertSame([['null' => true]], $this->filters['quote_item_id']);
        $this->assertArrayNotHasKey('attachment_id', $this->filters);
    }

    public function testGuestListIsScopedToSessionUploads(): void
    {
        $this->controller(['product_id' => 3], null, [7, 8])->execute();

        $this->assertSame(['files' => []], $this->jsonData);
        $this->assertSame([['null' => true]], $this->filters['customer_id']);
        $this->assertSame([['in' => [7, 8]]], $this->filters['attachment_id']);
    }
}
