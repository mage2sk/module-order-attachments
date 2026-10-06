<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Controller\Upload;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\MediaStorage\Model\File\Uploader;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Panth\Core\Security\UploadExtensionPolicy;
use Panth\OrderAttachments\Controller\Upload\Save;
use Panth\OrderAttachments\Model\AttachmentAccess;
use Panth\OrderAttachments\Model\OrderAttachment;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment as OrderAttachmentResource;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\Collection;
use Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\CollectionFactory;
use Panth\OrderAttachments\Model\Upload\FileValidator;
use Panth\OrderAttachments\Test\Unit\Controller\JsonResultTrait;
use Panth\OrderAttachments\Test\Unit\Fixture\AttachmentTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SaveTest extends TestCase
{
    use AttachmentTrait;
    use JsonResultTrait;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private array $params = ['product_id' => '3'];

    private bool $formKeyValid = true;

    private array $flags = [
        'panth_orderattachments/general/enabled' => true,
        'panth_orderattachments/general/enable_all_products' => false,
    ];

    private array $values = [
        'panth_orderattachments/upload/allowed_extensions' => 'PNG, pdf',
        'panth_orderattachments/upload/max_file_size' => '2',
    ];

    private mixed $productAttribute = '1';

    private bool $productExists = true;

    private ?int $customerId = null;

    private array $sessionIds = [];

    private int $recentUploads = 0;

    private array $fileInfo = [];

    private mixed $uploadResult = null;

    private ?OrderAttachment $savedAttachment = null;

    private ?int $rememberedId = null;

    private array $collectionFilters = [];

    private ?\Throwable $saveException = null;

    private ?string $tmpFile = null;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'oa_save_');
        file_put_contents($this->tmpFile, base64_decode(self::PNG_1X1));
        $this->fileInfo = ['name' => 'My Photo.png', 'size' => 68, 'tmp_name' => $this->tmpFile];
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->tmpFile && is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    private function controller(): Save
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn($k, $d = null) => $this->params[$k] ?? $d);

        $formKey = $this->createStub(FormKeyValidator::class);
        $formKey->method('validate')->willReturnCallback(fn() => $this->formKeyValid);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn($path) => (bool) ($this->flags[$path] ?? false));
        $scopeConfig->method('getValue')->willReturnCallback(fn($path) => $this->values[$path] ?? null);

        $uploader = $this->createStub(Uploader::class);
        $uploader->method('validateFile')->willReturnCallback(fn() => $this->fileInfo);
        $uploader->method('save')->willReturnCallback(function ($dir, $name) {
            return $this->uploadResult ?? ['file' => $name, 'size' => 68, 'path' => $dir];
        });
        $uploaderFactory = $this->createStub(UploaderFactory::class);
        $uploaderFactory->method('create')->willReturn($uploader);

        $directory = $this->createStub(WriteInterface::class);
        $directory->method('isDirectory')->willReturn(false);
        $directory->method('getAbsolutePath')->willReturnCallback(static fn($p = null) => '/var/www/var/' . $p);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($directory);

        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturnCallback(function () {
            if (!$this->productExists) {
                throw new NoSuchEntityException();
            }
            $product = $this->createStub(Product::class);
            $product->method('getData')->willReturnCallback(
                fn($key = '') => $key === 'panth_allow_order_attachment' ? $this->productAttribute : null
            );
            return $product;
        });

        $customer = new DataObject(['email' => 'jane@example.com']);
        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('getCustomer')->willReturn($customer);

        $checkoutSession = $this->createStub(CheckoutSession::class);
        $checkoutSession->method('getQuote')->willReturn(new DataObject(['customer_email' => 'guest@example.com']));

        $resource = $this->createStub(OrderAttachmentResource::class);
        $resource->method('save')->willReturnCallback(function (OrderAttachment $attachment) use (&$resource) {
            if ($this->saveException) {
                throw $this->saveException;
            }
            $attachment->setData('id', 77);
            $this->savedAttachment = $attachment;
            return $resource;
        });

        $select = $this->createStub(Select::class);
        $select->method('where')->willReturnSelf();
        $collection = $this->createStub(Collection::class);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $cond) use (&$collection) {
            $this->collectionFilters[$field] = $cond;
            return $collection;
        });
        $collection->method('getSize')->willReturnCallback(fn() => $this->recentUploads);
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $remote = $this->createStub(RemoteAddress::class);
        $remote->method('getRemoteAddress')->willReturn('203.0.113.9');

        $policy = $this->createStub(UploadExtensionPolicy::class);
        $policy->method('assertSafeExtension')->willReturnCallback(static function (string $filename) {
            if (str_ends_with(strtolower($filename), '.exe')) {
                throw new LocalizedException(__('Blocked by policy.'));
            }
        });

        $access = $this->createStub(AttachmentAccess::class);
        $access->method('getCurrentCustomerId')->willReturnCallback(fn() => $this->customerId);
        $access->method('getSessionUploadIds')->willReturnCallback(fn() => $this->sessionIds);
        $access->method('rememberUpload')->willReturnCallback(function (int $id) {
            $this->rememberedId = $id;
        });

        return new Save(
            $request,
            $this->jsonFactory(),
            $scopeConfig,
            $uploaderFactory,
            $filesystem,
            $productRepository,
            $customerSession,
            $checkoutSession,
            $this->attachmentFactoryStub(),
            $resource,
            $collectionFactory,
            $remote,
            $this->logger,
            $policy,
            $formKey,
            new FileValidator(),
            $access
        );
    }

    private function message(): string
    {
        return (string) ($this->jsonData['message'] ?? '');
    }

    public function testGuestUploadIsStoredInPrivateStorage(): void
    {
        $this->params['customer_note'] = '  ' . str_repeat('n', 600) . '  ';

        $this->controller()->execute();

        $this->assertTrue($this->jsonData['success'], $this->message());
        $this->assertSame(77, $this->jsonData['attachment_id']);
        $this->assertSame('My Photo.png', $this->jsonData['filename']);
        $this->assertSame(68, $this->jsonData['size']);
        $this->assertSame(77, $this->rememberedId);

        $saved = $this->savedAttachment;
        $this->assertSame(3, $saved->getData('product_id'));
        $this->assertNull($saved->getData('customer_id'));
        $this->assertSame('guest@example.com', $saved->getData('customer_email'));
        $this->assertSame('png', $saved->getData('file_extension'));
        $this->assertSame('image/png', $saved->getData('mime_type'));
        $this->assertSame(1, $saved->getData('status'));
        $this->assertSame(500, mb_strlen($saved->getData('customer_note')));
        $this->assertMatchesRegularExpression(
            '#^panth/order-attachments/3/[a-f0-9]{40}\.png$#',
            $saved->getData('file_path')
        );
        $this->assertSame(basename($saved->getData('file_path')), $saved->getData('stored_filename'));
    }

    public function testCustomerUploadUsesAccountEmailAndRateLimitScope(): void
    {
        $this->customerId = 12;

        $this->controller()->execute();

        $this->assertTrue($this->jsonData['success'], $this->message());
        $this->assertSame(12, $this->savedAttachment->getData('customer_id'));
        $this->assertSame('jane@example.com', $this->savedAttachment->getData('customer_email'));
        $this->assertSame(12, $this->collectionFilters['customer_id']);
    }

    public function testInvalidFormKey(): void
    {
        $this->formKeyValid = false;
        $this->controller()->execute();

        $this->assertFalse($this->jsonData['success']);
        $this->assertStringContainsString('Invalid form key', $this->message());
    }

    public function testDisabledModule(): void
    {
        $this->flags['panth_orderattachments/general/enabled'] = false;
        $this->controller()->execute();

        $this->assertSame('Order attachments feature is disabled.', $this->message());
        $this->assertNull($this->savedAttachment);
    }

    public function testHoneypotIsLoggedAndRejected(): void
    {
        $this->params['oa_website_url'] = 'http://spam';
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('OrderAttachments: Honeypot triggered', ['ip' => '203.0.113.9']);
        $this->logger = $logger;

        $this->controller()->execute();

        $this->assertSame('Upload validation failed. Please try again.', $this->message());
    }

    public function testRateLimitForGuestSession(): void
    {
        $this->sessionIds = [1, 2, 3];
        $this->recentUploads = 20;

        $this->controller()->execute();

        $this->assertSame(['in' => [1, 2, 3]], $this->collectionFilters['attachment_id']);
        $this->assertSame('Too many uploads. Please wait a few minutes before uploading more files.', $this->message());
        $this->assertNull($this->savedAttachment);
    }

    public function testBelowRateLimitIsAllowed(): void
    {
        $this->sessionIds = [1];
        $this->recentUploads = 19;

        $this->controller()->execute();

        $this->assertTrue($this->jsonData['success'], $this->message());
    }

    public function testMissingProductId(): void
    {
        $this->params = [];
        $this->controller()->execute();

        $this->assertSame('Product ID is required.', $this->message());
    }

    public function testUnknownProduct(): void
    {
        $this->productExists = false;
        $this->controller()->execute();

        $this->assertSame('Product not found.', $this->message());
    }

    public function testProductExplicitlyDisallowingAttachments(): void
    {
        $this->productAttribute = '0';
        $this->flags['panth_orderattachments/general/enable_all_products'] = true;
        $this->controller()->execute();

        $this->assertSame('This product does not allow file attachments.', $this->message());
    }

    public function testProductUsingConfigFallsBackToAllProductsFlag(): void
    {
        $this->productAttribute = null;
        $this->controller()->execute();
        $this->assertSame('This product does not allow file attachments.', $this->message());

        $this->flags['panth_orderattachments/general/enable_all_products'] = true;
        $this->productAttribute = '';
        $this->controller()->execute();
        $this->assertTrue($this->jsonData['success'], $this->message());
    }

    public function testPolicyBlockedExtension(): void
    {
        $this->fileInfo['name'] = 'setup.exe';
        $this->controller()->execute();

        $this->assertSame('Blocked by policy.', $this->message());
    }

    public function testExtensionOutsideConfiguredList(): void
    {
        $this->fileInfo['name'] = 'notes.txt';
        $this->controller()->execute();

        $this->assertSame('This file type is not allowed.', $this->message());
    }

    public function testOversizeFile(): void
    {
        $this->fileInfo['size'] = 3 * 1048576;
        $this->controller()->execute();

        $this->assertSame('File size exceeds the maximum allowed size of 2 MB.', $this->message());
    }

    public function testContentNotMatchingExtension(): void
    {
        $this->fileInfo['name'] = 'scan.pdf';
        $this->controller()->execute();

        $this->assertSame('The file content does not match its extension.', $this->message());
    }

    public function testFailedMoveIsReported(): void
    {
        $this->uploadResult = false;
        $this->controller()->execute();

        $this->assertSame('File upload failed.', $this->message());
        $this->assertNull($this->savedAttachment);
    }

    public function testUnexpectedErrorIsLoggedWithGenericMessage(): void
    {
        $this->saveException = new \RuntimeException('disk full');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('OrderAttachments upload error: disk full');
        $this->logger = $logger;

        $this->controller()->execute();

        $this->assertFalse($this->jsonData['success']);
        $this->assertStringStartsWith("Sorry, we couldn't upload your file.", $this->message());
    }

    public function testCsrfIsHandledByFormKey(): void
    {
        $controller = $this->controller();
        $request = $this->createStub(RequestInterface::class);

        $this->assertTrue($controller->validateForCsrf($request));
        $this->assertNull($controller->createCsrfValidationException($request));
    }
}
