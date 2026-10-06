<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Controller\Thumbnail;

use Magento\Framework\App\RequestInterface;
use Panth\OrderAttachments\Controller\Thumbnail\View;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Panth\OrderAttachments\Model\FileLocator;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as OrderAttachmentResource;
use Panth\OrderAttachments\Model\Upload\FileValidator;
use Panth\OrderAttachments\Test\Unit\Controller\JsonResultTrait;
use Panth\OrderAttachments\Test\Unit\Fixture\AttachmentTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ViewTest extends TestCase
{
    use AttachmentTrait;
    use JsonResultTrait;

    private const PATH = 'panth/order-attachments/3/abc.png';

    private function controller(
        array $params,
        array $rows,
        bool $canView = true,
        ?string $content = 'PNGDATA',
        ?LoggerInterface $logger = null,
        bool $throwOnLoad = false
    ): View {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($k, $d = null) => $params[$k] ?? $d);

        $resource = $this->createStub(OrderAttachmentResource::class);
        if ($throwOnLoad) {
            $resource->method('load')->willThrowException(new \RuntimeException('boom'));
        } else {
            $this->configureLoad($resource, $rows);
        }

        $access = $this->createStub(AttachmentAccess::class);
        $access->method('canView')->willReturn($canView);

        $locator = $this->createStub(FileLocator::class);
        $locator->method('readFile')->willReturn($content);

        return new View(
            $request,
            $this->rawFactory(),
            $this->attachmentFactoryStub(),
            $resource,
            $logger ?? $this->createStub(LoggerInterface::class),
            $access,
            $locator,
            new FileValidator()
        );
    }

    private function image(array $extra = []): array
    {
        return [5 => $extra + ['status' => 1, 'mime_type' => 'IMAGE/PNG', 'file_path' => self::PATH]];
    }

    public function testServesImageWithHardenedHeaders(): void
    {
        $this->controller(['id' => 5], $this->image())->execute();

        $this->assertSame(200, $this->responseCode);
        $this->assertSame('PNGDATA', $this->contents);
        $this->assertSame('image/png', $this->headers['Content-Type']);
        $this->assertSame('7', $this->headers['Content-Length']);
        $this->assertSame('nosniff', $this->headers['X-Content-Type-Options']);
        $this->assertSame("default-src 'none'; sandbox", $this->headers['Content-Security-Policy']);
        $this->assertSame('private, max-age=3600', $this->headers['Cache-Control']);
    }

    public function testMissingIdIs404(): void
    {
        $this->controller([], [])->execute();

        $this->assertSame(404, $this->responseCode);
        $this->assertSame('', $this->contents);
    }

    public function testInactiveAttachmentIs404(): void
    {
        $this->controller(['id' => 5], $this->image(['status' => 0]))->execute();

        $this->assertSame(404, $this->responseCode);
    }

    public function testForeignAttachmentIs404(): void
    {
        $this->controller(['id' => 5], $this->image(), false)->execute();

        $this->assertSame(404, $this->responseCode);
        $this->assertArrayNotHasKey('Content-Type', $this->headers);
    }

    public function testNonImageMimeIs404(): void
    {
        $this->controller(['id' => 5], $this->image(['mime_type' => 'image/svg+xml']))->execute();

        $this->assertSame(404, $this->responseCode);
    }

    public function testMissingFileIs404(): void
    {
        $this->controller(['id' => 5], $this->image(), true, null)->execute();

        $this->assertSame(404, $this->responseCode);
    }

    public function testExceptionIsLoggedAnd404(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('OrderAttachments thumbnail error: boom');

        $this->controller(['id' => 5], [], true, 'x', $logger, true)->execute();

        $this->assertSame(404, $this->responseCode);
    }
}
