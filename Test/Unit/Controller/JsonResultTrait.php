<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Controller;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;

/**
 * Captures what controllers write into JSON / raw results.
 */
trait JsonResultTrait
{
    private array $jsonData = [];

    private array $headers = [];

    private ?int $responseCode = null;

    private ?string $contents = null;

    private function jsonFactory(): JsonFactory
    {
        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use (&$json) {
            $this->jsonData = $data;
            return $json;
        });
        $json->method('setHeader')->willReturnCallback(function ($name, $value) use (&$json) {
            $this->headers[$name] = $value;
            return $json;
        });
        $factory = $this->createStub(JsonFactory::class);
        $factory->method('create')->willReturn($json);

        return $factory;
    }

    private function rawFactory(): RawFactory
    {
        $raw = $this->createStub(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($code) use (&$raw) {
            $this->responseCode = $code;
            return $raw;
        });
        $raw->method('setHeader')->willReturnCallback(function ($name, $value) use (&$raw) {
            $this->headers[$name] = $value;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function ($contents) use (&$raw) {
            $this->contents = $contents;
            return $raw;
        });
        $factory = $this->createStub(RawFactory::class);
        $factory->method('create')->willReturn($raw);

        return $factory;
    }
}
