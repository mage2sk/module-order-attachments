<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Panth\OrderAttachments\Test\Unit\Fixture\AttachmentTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AttachmentAccessTest extends TestCase
{
    use AttachmentTrait;

    private array $sessionData = [];

    private ?int $loggedInCustomerId = null;

    private ?int $quoteId = null;

    private array $quoteItemIds = [];

    private ?OrderRepositoryInterface $orderRepository = null;

    private ?string $guestCookie = null;

    private function access(): AttachmentAccess
    {
        $customerSession = $this->getMockBuilder(CustomerSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isLoggedIn', 'getCustomerId'])
            ->getMock();
        $customerSession->method('isLoggedIn')->willReturnCallback(fn() => $this->loggedInCustomerId !== null);
        $customerSession->method('getCustomerId')->willReturnCallback(fn() => $this->loggedInCustomerId);

        $checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', '__call', 'getQuoteId', 'getQuote'])
            ->getMock();
        $checkoutSession->method('getData')->willReturnCallback(fn($key = '') => $this->sessionData[$key] ?? null);
        $checkoutSession->method('__call')->willReturnCallback(function (string $method, array $args) use (&$checkoutSession) {
            if ($method === 'setData') {
                $this->sessionData[$args[0]] = $args[1] ?? null;
            }
            return $checkoutSession;
        });
        $checkoutSession->method('getQuoteId')->willReturnCallback(fn() => $this->quoteId);
        $checkoutSession->method('getQuote')->willReturnCallback(function () {
            $quote = $this->createStub(Quote::class);
            $quote->method('getAllItems')->willReturn(array_map(
                static fn(int $id) => new DataObject(['id' => $id]),
                $this->quoteItemIds
            ));
            return $quote;
        });

        $cookieManager = $this->createStub(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturnCallback(fn() => $this->guestCookie);

        return new AttachmentAccess(
            $customerSession,
            $checkoutSession,
            $this->orderRepository ?? $this->createStub(OrderRepositoryInterface::class),
            $cookieManager
        );
    }

    public function testRememberUploadStoresUniqueIds(): void
    {
        $access = $this->access();
        $access->rememberUpload(5);
        $access->rememberUpload(7);
        $access->rememberUpload(5);

        $this->assertSame([5, 7], $access->getSessionUploadIds());
    }

    public function testRememberUploadIgnoresNonPositiveIds(): void
    {
        $access = $this->access();
        $access->rememberUpload(0);
        $access->rememberUpload(-3);

        $this->assertSame([], $access->getSessionUploadIds());
        $this->assertArrayNotHasKey('panth_order_attachment_ids', $this->sessionData);
    }

    public function testRememberUploadKeepsOnlyTheNewest200Ids(): void
    {
        $this->sessionData['panth_order_attachment_ids'] = range(1, 200);
        $access = $this->access();
        $access->rememberUpload(201);

        $ids = $access->getSessionUploadIds();
        $this->assertCount(200, $ids);
        $this->assertSame(2, $ids[0]);
        $this->assertSame(201, $ids[199]);
    }

    public function testSessionIdsAreSanitized(): void
    {
        $this->sessionData['panth_order_attachment_ids'] = ['3', 'abc', 0, 9];

        $this->assertSame([3, 9], $this->access()->getSessionUploadIds());
    }

    public function testSessionIdsAreEmptyWhenNotAnArray(): void
    {
        $this->sessionData['panth_order_attachment_ids'] = 'garbage';

        $this->assertSame([], $this->access()->getSessionUploadIds());
    }

    public function testCurrentCustomerId(): void
    {
        $this->assertNull($this->access()->getCurrentCustomerId());

        $this->loggedInCustomerId = 0;
        $this->assertNull($this->access()->getCurrentCustomerId());

        $this->loggedInCustomerId = 12;
        $this->assertSame(12, $this->access()->getCurrentCustomerId());
    }

    public function testUnsavedAttachmentIsNeverOwned(): void
    {
        $this->assertFalse($this->access()->isOwnedByVisitor($this->makeAttachment()));
    }

    public function testCustomerAttachmentIsOwnedOnlyByThatCustomer(): void
    {
        $attachment = $this->makeAttachment(['attachment_id' => 4, 'customer_id' => 12]);
        $this->sessionData['panth_order_attachment_ids'] = [4];

        $this->assertFalse($this->access()->isOwnedByVisitor($attachment), 'session id must not override customer ownership');

        $this->loggedInCustomerId = 99;
        $this->assertFalse($this->access()->isOwnedByVisitor($attachment));

        $this->loggedInCustomerId = 12;
        $this->assertTrue($this->access()->isOwnedByVisitor($attachment));
    }

    public function testGuestAttachmentOwnedThroughSession(): void
    {
        $this->sessionData['panth_order_attachment_ids'] = [4];

        $this->assertTrue($this->access()->isOwnedByVisitor($this->makeAttachment(['attachment_id' => 4])));
        $this->assertFalse($this->access()->isOwnedByVisitor($this->makeAttachment(['attachment_id' => 5])));
    }

    public function testGuestAttachmentOwnedThroughCurrentQuoteItem(): void
    {
        $this->quoteId = 3;
        $this->quoteItemIds = [10, 11];

        $this->assertTrue($this->access()->isOwnedByVisitor(
            $this->makeAttachment(['attachment_id' => 4, 'quote_item_id' => 11])
        ));
        $this->assertFalse($this->access()->isOwnedByVisitor(
            $this->makeAttachment(['attachment_id' => 4, 'quote_item_id' => 12])
        ));
    }

    public function testCanManageRejectsOrderedAttachments(): void
    {
        $this->sessionData['panth_order_attachment_ids'] = [4];

        $this->assertTrue($this->access()->canManage($this->makeAttachment(['attachment_id' => 4])));
        $this->assertFalse($this->access()->canManage($this->makeAttachment(['attachment_id' => 4, 'order_id' => 8])));
    }

    public function testCanViewOrderedAttachmentRequiresOrderOwner(): void
    {
        $attachment = $this->makeAttachment(['attachment_id' => 4, 'order_id' => 8, 'customer_id' => 12]);
        $this->sessionData['panth_order_attachment_ids'] = [4];

        $this->assertFalse($this->access()->canView($attachment));

        $this->loggedInCustomerId = 12;
        $this->assertTrue($this->access()->canView($attachment));
    }

    public function testCanViewUnorderedAttachmentUsesVisitorOwnership(): void
    {
        $this->sessionData['panth_order_attachment_ids'] = [4];

        $this->assertTrue($this->access()->canView($this->makeAttachment(['attachment_id' => 4])));
    }

    public function testOrderOwnerResolvedThroughOrderCustomer(): void
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getCustomerId')->willReturn(12);
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects($this->once())->method('get')->with(8)->willReturn($order);
        $this->orderRepository = $repository;
        $this->loggedInCustomerId = 12;

        $this->assertTrue($this->access()->isOrderOwner(
            $this->makeAttachment(['attachment_id' => 4, 'order_id' => 8])
        ));
    }

    public function testOrderOwnerIsFalseForOtherCustomersOrder(): void
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getCustomerId')->willReturn(55);
        $repository = $this->createStub(OrderRepositoryInterface::class);
        $repository->method('get')->willReturn($order);
        $this->orderRepository = $repository;
        $this->loggedInCustomerId = 12;

        $this->assertFalse($this->access()->isOrderOwner(
            $this->makeAttachment(['attachment_id' => 4, 'order_id' => 8])
        ));
    }

    public function testOrderOwnerIsFalseWhenOrderMissing(): void
    {
        $repository = $this->createStub(OrderRepositoryInterface::class);
        $repository->method('get')->willThrowException(new NoSuchEntityException());
        $this->orderRepository = $repository;
        $this->loggedInCustomerId = 12;

        $this->assertFalse($this->access()->isOrderOwner(
            $this->makeAttachment(['attachment_id' => 4, 'order_id' => 8])
        ));
    }

    public function testOrderOwnerIsFalseWithoutOrderOrLogin(): void
    {
        $this->assertFalse($this->access()->isOrderOwner($this->makeAttachment(['customer_id' => 12])));

        $this->loggedInCustomerId = 12;
        $this->assertFalse($this->access()->isOrderOwner($this->makeAttachment(['customer_id' => 3])));
    }

    public function testCurrentQuoteItemNeedsActiveQuote(): void
    {
        $this->quoteItemIds = [10];

        $this->assertFalse($this->access()->isCurrentQuoteItem(10));
        $this->assertFalse($this->access()->isCurrentQuoteItem(0));

        $this->quoteId = 1;
        $this->assertTrue($this->access()->isCurrentQuoteItem(10));
        $this->assertFalse($this->access()->isCurrentQuoteItem(11));
    }

    private function guestOrder(int $customerId = 0, string $protectCode = 'abc123', string $incrementId = '000000227'): void
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getCustomerId')->willReturn($customerId ?: null);
        $order->method('getProtectCode')->willReturn($protectCode);
        $order->method('getIncrementId')->willReturn($incrementId);
        $repository = $this->createStub(OrderRepositoryInterface::class);
        $repository->method('get')->willReturn($order);
        $this->orderRepository = $repository;
    }

    public function testGuestOrderViewerWithValidGuestCookieCanView(): void
    {
        $this->guestOrder();
        $this->guestCookie = base64_encode('abc123:000000227');
        $attachment = $this->makeAttachment(['attachment_id' => 41, 'order_id' => 299]);

        $this->assertTrue($this->access()->isGuestOrderViewer($attachment));
        $this->assertTrue($this->access()->canView($attachment));
    }

    public function testGuestOrderViewerRejectsWrongProtectCode(): void
    {
        $this->guestOrder();
        $this->guestCookie = base64_encode('wrong:000000227');

        $this->assertFalse($this->access()->canView($this->makeAttachment(['attachment_id' => 41, 'order_id' => 299])));
    }

    public function testGuestOrderViewerRejectsOtherIncrementId(): void
    {
        $this->guestOrder();
        $this->guestCookie = base64_encode('abc123:000000999');

        $this->assertFalse($this->access()->isGuestOrderViewer(
            $this->makeAttachment(['attachment_id' => 41, 'order_id' => 299])
        ));
    }

    public function testGuestOrderViewerRejectsCustomerOrders(): void
    {
        $this->guestOrder(21);
        $this->guestCookie = base64_encode('abc123:000000227');

        $this->assertFalse($this->access()->isGuestOrderViewer(
            $this->makeAttachment(['attachment_id' => 41, 'order_id' => 299])
        ));
    }

    public function testGuestOrderViewerRejectsMissingOrMalformedCookie(): void
    {
        $this->guestOrder();
        $attachment = $this->makeAttachment(['attachment_id' => 41, 'order_id' => 299]);

        $this->assertFalse($this->access()->isGuestOrderViewer($attachment));

        $this->guestCookie = 'not base64 !!';
        $this->assertFalse($this->access()->isGuestOrderViewer($attachment));

        $this->guestCookie = base64_encode('no-separator');
        $this->assertFalse($this->access()->isGuestOrderViewer($attachment));

        $this->guestCookie = base64_encode(':000000227');
        $this->assertFalse($this->access()->isGuestOrderViewer($attachment));
    }

    public function testGuestOrderViewerNeedsOrderAndExistingOrder(): void
    {
        $this->guestCookie = base64_encode('abc123:000000227');
        $this->assertFalse($this->access()->isGuestOrderViewer($this->makeAttachment(['attachment_id' => 41])));

        $repository = $this->createStub(OrderRepositoryInterface::class);
        $repository->method('get')->willThrowException(new NoSuchEntityException());
        $this->orderRepository = $repository;
        $this->assertFalse($this->access()->isGuestOrderViewer(
            $this->makeAttachment(['attachment_id' => 41, 'order_id' => 299])
        ));
    }
}
