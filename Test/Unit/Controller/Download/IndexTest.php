<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Controller\Download;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Panth\OrderAttachments\Controller\Download\Index;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Panth\OrderAttachments\Model\FileLocator;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as OrderAttachmentResource;
use Panth\OrderAttachments\Test\Unit\Fixture\AttachmentTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IndexTest extends TestCase
{
    use AttachmentTrait;

    private const PATH = 'panth/order-attachments/3/abc.pdf';

    private array $fileArgs = [];

    private array $errors = [];

    private ?string $redirectPath = null;

    private Redirect $redirect;

    private ResponseInterface $fileResponse;

    private function controller(
        array $params,
        array $rows,
        bool $canView = true,
        bool $loggedIn = false,
        ?string $absolutePath = '/var/www/var/' . self::PATH,
        ?OrderAttachmentResource $resource = null,
        ?LoggerInterface $logger = null
    ): Index {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($k, $d = null) => $params[$k] ?? $d);

        $this->fileResponse = $this->createStub(ResponseInterface::class);
        $fileFactory = $this->createStub(FileFactory::class);
        $fileFactory->method('create')->willReturnCallback(function (...$args) {
            $this->fileArgs = $args;
            return $this->fileResponse;
        });

        $this->redirect = $this->createStub(Redirect::class);
        $this->redirect->method('setPath')->willReturnCallback(function ($path) {
            $this->redirectPath = $path;
            return $this->redirect;
        });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('isLoggedIn')->willReturn($loggedIn);

        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use (&$messages) {
            $this->errors[] = (string) $message;
            return $messages;
        });

        if ($resource === null) {
            $resource = $this->createStub(OrderAttachmentResource::class);
            $this->configureLoad($resource, $rows);
        }

        $access = $this->createStub(AttachmentAccess::class);
        $access->method('canView')->willReturn($canView);

        $locator = $this->createStub(FileLocator::class);
        $locator->method('getAbsolutePath')->willReturn($absolutePath);

        return new Index(
            $request,
            $fileFactory,
            $redirectFactory,
            $customerSession,
            $this->attachmentFactoryStub(),
            $resource,
            $messages,
            $logger ?? $this->createStub(LoggerInterface::class),
            $access,
            $locator
        );
    }

    public function testStreamsFileWithSanitizedName(): void
    {
        $result = $this->controller(['id' => 5], [5 => [
            'status' => 1,
            'file_path' => self::PATH,
            'original_filename' => "in\"vo/ice;\r\n.pdf",
        ]])->execute();

        $this->assertSame($this->fileResponse, $result);
        $this->assertSame('invoice.pdf', $this->fileArgs[0]);
        $this->assertSame(['type' => 'filename', 'value' => '/var/www/var/' . self::PATH], $this->fileArgs[1]);
        $this->assertSame(DirectoryList::ROOT, $this->fileArgs[2]);
        $this->assertSame('application/octet-stream', $this->fileArgs[3]);
    }

    public function testEmptySanitizedNameFallsBack(): void
    {
        $this->controller(['id' => 5], [5 => ['status' => 1, 'file_path' => self::PATH, 'original_filename' => '"/;']])
            ->execute();

        $this->assertSame('attachment', $this->fileArgs[0]);
    }

    public function testMissingIdRedirectsHomeWithError(): void
    {
        $result = $this->controller([], [])->execute();

        $this->assertSame($this->redirect, $result);
        $this->assertSame('/', $this->redirectPath);
        $this->assertSame(['Attachment ID is required.'], $this->errors);
    }

    public function testDisabledAttachmentIsNotFound(): void
    {
        $this->controller(['id' => 5], [5 => ['status' => 0, 'file_path' => self::PATH]])->execute();

        $this->assertSame(['Attachment not found.'], $this->errors);
        $this->assertSame([], $this->fileArgs);
    }

    public function testGuestIsAskedToLogIn(): void
    {
        $this->controller(['id' => 5], [5 => ['status' => 1, 'file_path' => self::PATH]], false, false)->execute();

        $this->assertSame(['Please log in to download attachments.'], $this->errors);
    }

    public function testLoggedInStrangerIsDenied(): void
    {
        $this->controller(['id' => 5], [5 => ['status' => 1, 'file_path' => self::PATH]], false, true)->execute();

        $this->assertSame(['You are not authorized to access this attachment.'], $this->errors);
        $this->assertSame([], $this->fileArgs);
    }

    public function testMissingFileOnDisk(): void
    {
        $this->controller(['id' => 5], [5 => ['status' => 1, 'file_path' => self::PATH]], true, false, null)->execute();

        $this->assertSame(['The requested file no longer exists.'], $this->errors);
    }

    public function testUnexpectedErrorIsLogged(): void
    {
        $resource = $this->createStub(OrderAttachmentResource::class);
        $resource->method('load')->willThrowException(new \RuntimeException('db'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('OrderAttachments download error: db');

        $this->controller(['id' => 5], [], true, false, null, $resource, $logger)->execute();

        $this->assertSame(['An error occurred while downloading the file.'], $this->errors);
        $this->assertSame('/', $this->redirectPath);
    }
}
