<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Controller\Adminhtml\Attachment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Panth\OrderAttachments\Controller\Adminhtml\Attachment\Download;
use Panth\OrderAttachments\Controller\Adminhtml\Attachment\Preview;
use Panth\OrderAttachments\Model\FileLocator;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as OrderAttachmentResource;
use Panth\OrderAttachments\Model\Upload\FileValidator;
use Panth\OrderAttachments\Test\Unit\Controller\JsonResultTrait;
use Panth\OrderAttachments\Test\Unit\Fixture\AttachmentTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AdminControllersTest extends TestCase
{
    use AttachmentTrait;
    use JsonResultTrait;

    private const PATH = 'panth/order-attachments/3/abc.png';

    private array $errors = [];

    private ?string $redirectPath = null;

    private array $fileArgs = [];

    private function context(array $params): Context
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($k, $d = null) => $params[$k] ?? $d);

        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use (&$messages) {
            $this->errors[] = (string) $message;
            return $messages;
        });

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path) use (&$redirect) {
            $this->redirectPath = $path;
            return $redirect;
        });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);

        return $context;
    }

    private function download(array $params, array $rows, ?string $absolutePath, ?LoggerInterface $logger = null, bool $throw = false): Download
    {
        $fileFactory = $this->createStub(FileFactory::class);
        $fileFactory->method('create')->willReturnCallback(function (...$args) {
            $this->fileArgs = $args;
            return $this->createStub(ResponseInterface::class);
        });
        $locator = $this->createStub(FileLocator::class);
        $locator->method('getAbsolutePath')->willReturn($absolutePath);
        $resource = $this->createStub(OrderAttachmentResource::class);
        if ($throw) {
            $resource->method('load')->willThrowException(new \RuntimeException('db'));
        } else {
            $this->configureLoad($resource, $rows);
        }

        return new Download(
            $this->context($params),
            $fileFactory,
            $locator,
            $this->attachmentFactoryStub(),
            $resource,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    public function testAdminDownloadStreamsFileRegardlessOfStatus(): void
    {
        $this->download(['id' => 5], [5 => ['status' => 0, 'original_filename' => 'a\\b.png']], '/abs/file')->execute();

        $this->assertSame('ab.png', $this->fileArgs[0]);
        $this->assertSame(['type' => 'filename', 'value' => '/abs/file'], $this->fileArgs[1]);
        $this->assertSame(DirectoryList::ROOT, $this->fileArgs[2]);
        $this->assertNull($this->redirectPath);
    }

    public function testAdminDownloadErrorsRedirectToGrid(): void
    {
        $this->download([], [], '/abs/file')->execute();
        $this->download(['id' => 9], [], '/abs/file')->execute();
        $this->download(['id' => 5], [5 => ['original_filename' => 'a.png']], null)->execute();

        $this->assertSame([
            'Attachment ID is required.',
            'Attachment not found.',
            'The requested file no longer exists.',
        ], $this->errors);
        $this->assertSame('panth_orderattachments/attachment/index', $this->redirectPath);
        $this->assertSame([], $this->fileArgs);
    }

    public function testAdminDownloadFallbackNameAndUnexpectedError(): void
    {
        $this->download(['id' => 5], [5 => ['original_filename' => ';;']], '/abs/file')->execute();
        $this->assertSame('attachment', $this->fileArgs[0]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('OrderAttachments admin download error: db');
        $this->download(['id' => 5], [], null, $logger, true)->execute();

        $this->assertSame(['An error occurred while downloading the file.'], $this->errors);
    }

    private function preview(array $params, array $rows, ?string $content): Preview
    {
        $locator = $this->createStub(FileLocator::class);
        $locator->method('readFile')->willReturn($content);
        $resource = $this->createStub(OrderAttachmentResource::class);
        $this->configureLoad($resource, $rows);

        return new Preview(
            $this->context($params),
            $this->rawFactory(),
            $locator,
            new FileValidator(),
            $this->attachmentFactoryStub(),
            $resource
        );
    }

    public function testAdminPreviewServesImages(): void
    {
        $this->preview(['id' => 5], [5 => ['mime_type' => 'image/jpeg', 'file_path' => self::PATH]], 'JPEG')->execute();

        $this->assertSame(200, $this->responseCode);
        $this->assertSame('JPEG', $this->contents);
        $this->assertSame('image/jpeg', $this->headers['Content-Type']);
        $this->assertSame('4', $this->headers['Content-Length']);
        $this->assertSame('inline', $this->headers['Content-Disposition']);
    }

    public function testAdminPreviewReturns404ForInvalidRequests(): void
    {
        foreach ([
            [[], [], 'x'],
            [['id' => 9], [], 'x'],
            [['id' => 5], [5 => ['mime_type' => 'application/pdf']], 'x'],
            [['id' => 5], [5 => ['mime_type' => 'image/png']], null],
        ] as [$params, $rows, $content]) {
            $this->headers = [];
            $this->preview($params, $rows, $content)->execute();

            $this->assertSame(404, $this->responseCode);
            $this->assertSame('', $this->contents);
            $this->assertArrayNotHasKey('Content-Type', $this->headers);
        }
    }
}
