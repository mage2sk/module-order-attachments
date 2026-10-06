<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Fixture;

use Magento\Framework\DataObject;

/**
 * Minimal quote item double exposing the option API used by the cart plugins.
 */
class FakeQuoteItem extends DataObject
{
    public array $addedOptions = [];

    public int $saveCount = 0;

    public ?FakeOption $option = null;

    public function getOptionByCode(string $code): ?FakeOption
    {
        return $code === 'additional_options' ? $this->option : null;
    }

    public function addOption(array $option): self
    {
        $this->addedOptions[] = $option;

        return $this;
    }

    public function save(): self
    {
        $this->saveCount++;

        return $this;
    }

    public function lastOptions(): array
    {
        $last = end($this->addedOptions);

        return $last ? json_decode($last['value'], true) : [];
    }
}
