<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Fixture;

use Magento\Framework\DataObject;

/**
 * Quote item option double that records deletion.
 */
class FakeOption extends DataObject
{
    public bool $deleted = false;

    public static function withOptions(array $options): self
    {
        return new self(['value' => json_encode($options)]);
    }

    public function delete(): self
    {
        $this->deleted = true;

        return $this;
    }
}
