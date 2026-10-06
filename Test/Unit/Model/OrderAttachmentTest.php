<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Model;

use Panth\OrderAttachments\Model\Product\Attribute\Source\AllowOrderAttachment;
use Panth\OrderAttachments\Test\Unit\Fixture\AttachmentTrait;
use PHPUnit\Framework\TestCase;

class OrderAttachmentTest extends TestCase
{
    use AttachmentTrait;

    public function testNullableIdsStayNullWhenUnset(): void
    {
        $attachment = $this->makeAttachment();

        $this->assertNull($attachment->getAttachmentId());
        $this->assertNull($attachment->getQuoteItemId());
        $this->assertNull($attachment->getOrderItemId());
        $this->assertNull($attachment->getOrderId());
        $this->assertNull($attachment->getCustomerId());
    }

    public function testNumericStringsAreCastToInt(): void
    {
        $attachment = $this->makeAttachment([
            'attachment_id' => '4',
            'quote_item_id' => '10',
            'order_item_id' => '11',
            'order_id' => '12',
            'customer_id' => '13',
            'product_id' => '14',
            'file_size' => '2048',
            'status' => '1',
        ]);

        $this->assertSame(4, $attachment->getAttachmentId());
        $this->assertSame(10, $attachment->getQuoteItemId());
        $this->assertSame(11, $attachment->getOrderItemId());
        $this->assertSame(12, $attachment->getOrderId());
        $this->assertSame(13, $attachment->getCustomerId());
        $this->assertSame(14, $attachment->getProductId());
        $this->assertSame(2048, $attachment->getFileSize());
        $this->assertSame(1, $attachment->getStatus());
    }

    public function testNonNullableFieldsDefaultToEmptyValues(): void
    {
        $attachment = $this->makeAttachment();

        $this->assertSame(0, $attachment->getProductId());
        $this->assertSame(0, $attachment->getFileSize());
        $this->assertSame(0, $attachment->getStatus());
        $this->assertSame('', $attachment->getOriginalFilename());
        $this->assertSame('', $attachment->getStoredFilename());
        $this->assertSame('', $attachment->getFilePath());
    }

    public function testSettersClearNullableIds(): void
    {
        $attachment = $this->makeAttachment(['quote_item_id' => 10]);
        $attachment->setQuoteItemId(null)->setOrderId(5);

        $this->assertNull($attachment->getQuoteItemId());
        $this->assertSame(5, $attachment->getOrderId());
    }

    public function testAllowOrderAttachmentSourceOptions(): void
    {
        $source = new AllowOrderAttachment();
        $options = $source->getAllOptions();

        $this->assertSame(['', 1, 0], array_column($options, 'value'));
        $this->assertSame(
            ['Use Config Setting', 'Yes', 'No'],
            array_map(static fn($o) => (string) $o['label'], $options)
        );
        $this->assertSame($options, $source->getAllOptions(), 'options are memoized');
    }
}
