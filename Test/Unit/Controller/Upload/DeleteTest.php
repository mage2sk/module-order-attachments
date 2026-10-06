<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Controller\Upload;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Panth\OrderAttachments\Controller\Upload\Delete;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as OrderAttachmentResource;
use Panth\OrderAttachments\Test\Unit\Controller\JsonResultTrait;
use Panth\OrderAttachments\Test\Unit\Fixture\AttachmentTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeleteTest extends TestCase
{
    use AttachmentTrait;
    use JsonResultTrait;

    private function controller(
        array $params,
        OrderAttachmentResource $resource,
        bool $formKeyValid = true,
        bool $canManage = true,
        ?LoggerInterface $logger = null
    ): Delete {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($k, $d = null) => $params[$k] ?? $d);
        $formKey = $this->createStub(FormKeyValidator::class);
        $formKey->method('validate')->willReturn($formKeyValid);
        $access = $this->createStub(AttachmentAccess::class);
        $access->method('canManage')->willReturn($canManage);

        return new Delete(
            $request,
            $this->jsonFactory(),
            $this->createStub(CustomerSession::class),
            $this->attachmentFactoryStub(),
            $resource,
            $logger ?? $this->createStub(LoggerInterface::class),
            $formKey,
            $access
        );
    }

    public function testSoftDeletesOwnedAttachment(): void
    {
        $saved = null;
        $resource = $this->createMock(OrderAttachmentResource::class);
        $this->configureLoad($resource, [5 => ['status' => 1]]);
        $resource->expects($this->once())->method('save')->willReturnCallback(
            function ($attachment) use (&$saved, $resource) {
                $saved = $attachment;
                return $resource;
            }
        );

        $this->controller(['attachment_id' => '5'], $resource)->execute();

        $this->assertTrue($this->jsonData['success']);
        $this->assertSame('Attachment has been removed.', (string) $this->jsonData['message']);
        $this->assertSame(0, $saved->getData('status'));
    }

    public function testInvalidFormKeyIsRejected(): void
    {
        $resource = $this->createMock(OrderAttachmentResource::class);
        $resource->expects($this->never())->method('load');

        $this->controller(['attachment_id' => 5], $resource, false)->execute();

        $this->assertFalse($this->jsonData['success']);
        $this->assertStringContainsString('Invalid form key', (string) $this->jsonData['message']);
    }

    public function testMissingIdIsRejected(): void
    {
        $this->controller([], $this->createStub(OrderAttachmentResource::class))->execute();

        $this->assertSame(
            ['success' => false, 'message' => 'Attachment ID is required.'],
            ['success' => $this->jsonData['success'], 'message' => (string) $this->jsonData['message']]
        );
    }

    public function testUnknownAttachmentIsRejected(): void
    {
        $resource = $this->createStub(OrderAttachmentResource::class);
        $this->configureLoad($resource, []);

        $this->controller(['attachment_id' => 9], $resource)->execute();

        $this->assertSame('Attachment not found.', (string) $this->jsonData['message']);
    }

    public function testOrderedAttachmentCannotBeDeleted(): void
    {
        $resource = $this->createMock(OrderAttachmentResource::class);
        $this->configureLoad($resource, [5 => ['order_id' => 3]]);
        $resource->expects($this->never())->method('save');

        $this->controller(['attachment_id' => 5], $resource)->execute();

        $this->assertSame('Cannot delete attachments from placed orders.', (string) $this->jsonData['message']);
    }

    public function testForeignAttachmentCannotBeDeleted(): void
    {
        $resource = $this->createMock(OrderAttachmentResource::class);
        $this->configureLoad($resource, [5 => ['status' => 1]]);
        $resource->expects($this->never())->method('save');

        $this->controller(['attachment_id' => 5], $resource, true, false)->execute();

        $this->assertFalse($this->jsonData['success']);
        $this->assertSame('You are not authorized to delete this attachment.', (string) $this->jsonData['message']);
    }

    public function testUnexpectedErrorIsLoggedWithGenericMessage(): void
    {
        $resource = $this->createStub(OrderAttachmentResource::class);
        $this->configureLoad($resource, [5 => ['status' => 1]]);
        $resource->method('save')->willThrowException(new \RuntimeException('deadlock'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('OrderAttachments delete error: deadlock');

        $this->controller(['attachment_id' => 5], $resource, true, true, $logger)->execute();

        $this->assertSame('An error occurred while removing the attachment.', (string) $this->jsonData['message']);
    }

    public function testCsrfValidationIsDelegatedToFormKey(): void
    {
        $controller = $this->controller([], $this->createStub(OrderAttachmentResource::class));
        $request = $this->createStub(RequestInterface::class);

        $this->assertTrue($controller->validateForCsrf($request));
        $this->assertNull($controller->createCsrfValidationException($request));
    }
}
