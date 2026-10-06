<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Fixture;

use Panth\OrderAttachments\Model\OrderAttachment;

/**
 * Builds OrderAttachment models without touching the resource layer.
 */
trait AttachmentTrait
{
    private function makeAttachment(array $data = []): OrderAttachment
    {
        /** @var OrderAttachment $attachment */
        $attachment = (new \ReflectionClass(OrderAttachment::class))->newInstanceWithoutConstructor();
        foreach ($data as $key => $value) {
            $attachment->setData($key, $value);
        }
        if (isset($data['attachment_id']) && !isset($data['id'])) {
            $attachment->setData('id', $data['attachment_id']);
        }

        return $attachment;
    }

    /**
     * Makes resource->load() hydrate the model from $rows keyed by id.
     */
    private function configureLoad(object $resource, array $rows): void
    {
        $resource->method('load')->willReturnCallback(
            static function ($object, $id) use ($rows, $resource) {
                if (isset($rows[$id])) {
                    foreach ($rows[$id] + ['attachment_id' => $id, 'id' => $id] as $key => $value) {
                        $object->setData($key, $value);
                    }
                }
                return $resource;
            }
        );
    }

    private function attachmentFactoryStub(): object
    {
        $factory = $this->createStub(\Panth\OrderAttachments\Model\OrderAttachmentFactory::class);
        $factory->method('create')->willReturnCallback(fn() => $this->makeAttachment());

        return $factory;
    }
}
