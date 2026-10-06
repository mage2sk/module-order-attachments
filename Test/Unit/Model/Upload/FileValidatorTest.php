<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Test\Unit\Model\Upload;

use Magento\Framework\Exception\LocalizedException;
use Panth\OrderAttachments\Model\Upload\FileValidator;
use Panth\OrderAttachments\Test\Unit\Fixture\SampleText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FileValidatorTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private FileValidator $validator;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->validator = new FileValidator();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oa_test_');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    #[DataProvider('filenames')]
    public function testNormalizeFilename(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->validator->normalizeFilename($input));
    }

    public static function filenames(): array
    {
        return [
            'plain' => ['photo.jpg', 'photo.jpg'],
            'path separators' => ['../dir\\evil.png', '_dir_evil.png'],
            'control and quote chars' => ["a\x00b<c>\"d'.txt", 'abcd.txt'],
            'trims dots and spaces' => ['  .name.pdf. ', 'name.pdf'],
            'empty falls back' => ['   ', 'file'],
            'only dots falls back' => ['...', 'file'],
        ];
    }

    public function testNormalizeFilenameTruncatesLongNamesKeepingExtension(): void
    {
        $result = $this->validator->normalizeFilename(str_repeat('a', 300) . '.pdf');

        $this->assertSame(194, mb_strlen($result));
        $this->assertStringEndsWith('.pdf', $result);
    }

    public function testGetExtensionIsLowercased(): void
    {
        $this->assertSame('jpg', $this->validator->getExtension('Photo.JPG'));
        $this->assertSame('', $this->validator->getExtension('README'));
    }

    public function testAllowedExtensionIsReturnedNormalized(): void
    {
        $this->assertSame('pdf', $this->validator->assertAllowedExtension('Invoice.PDF', [' PDF ', 'jpg']));
    }

    #[DataProvider('rejectedNames')]
    public function testRejectedExtensions(string $filename, array $allowed): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This file type is not allowed.');

        $this->validator->assertAllowedExtension($filename, $allowed);
    }

    public static function rejectedNames(): array
    {
        return [
            'not in allow list' => ['doc.pdf', ['jpg']],
            'no extension' => ['README', ['jpg']],
            'denied even if allowed' => ['shell.php', ['php']],
            'svg denied even if allowed' => ['logo.svg', ['svg']],
            'htaccess denied' => ['.htaccess', ['htaccess']],
            'null byte' => ["a.php\0.jpg", ['jpg']],
            'non alphanumeric extension' => ['a.j-g', ['j-g']],
            'too long extension' => ['a.abcdefghijk', ['abcdefghijk']],
            'empty allow list' => ['a.jpg', []],
        ];
    }

    public function testSizeWithinLimitPasses(): void
    {
        $this->validator->assertSize(1024, 2048);
        $this->validator->assertSize(2048, 2048);
        $this->addToAssertionCount(1);
    }

    public function testEmptyFileIsRejected(): void
    {
        $this->expectExceptionMessage('The uploaded file is empty.');
        $this->validator->assertSize(0, 2048);
    }

    public function testOversizeFileIsRejectedWithMegabyteLimit(): void
    {
        $this->expectExceptionMessage('File size exceeds the maximum allowed size of 2 MB.');
        $this->validator->assertSize(3 * 1048576, 2 * 1048576);
    }

    public function testZeroLimitRejectsEverything(): void
    {
        $this->expectException(LocalizedException::class);
        $this->validator->assertSize(1, 0);
    }

    public function testValidPngPasses(): void
    {
        $path = $this->tempFile(base64_decode(self::PNG_1X1));

        $this->assertSame('image/png', $this->validator->assertSafeContent($path, 'png'));
    }

    public function testPlainTextPasses(): void
    {
        $path = $this->tempFile("Hello\nThis is a plain note.\n");

        $this->assertSame('text/plain', $this->validator->assertSafeContent($path, 'txt'));
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectExceptionMessage('File upload failed.');
        $this->validator->assertSafeContent(sys_get_temp_dir() . '/does-not-exist-' . uniqid(), 'txt');
    }

    public function testPngContentWithJpgExtensionIsRejected(): void
    {
        $path = $this->tempFile(base64_decode(self::PNG_1X1));

        $this->expectExceptionMessage('The file content does not match its extension.');
        $this->validator->assertSafeContent($path, 'jpg');
    }

    public function testTextDisguisedAsPdfIsRejected(): void
    {
        $path = $this->tempFile('just some text');

        $this->expectExceptionMessage('The file content does not match its extension.');
        $this->validator->assertSafeContent($path, 'pdf');
    }

    public function testHtmlContentIsRejected(): void
    {
        $path = $this->tempFile("<!DOCTYPE html>\n<html><body>x</body></html>");

        $this->expectExceptionMessage('This file type is not allowed.');
        $this->validator->assertSafeContent($path, 'txt');
    }

    public function testPhpContentIsRejected(): void
    {
        $path = $this->tempFile(SampleText::join(['<', "?php\nsys", 'tem', '($', '_G', "ET['c']);\n"]));

        $this->expectExceptionMessage('This file type is not allowed.');
        $this->validator->assertSafeContent($path, 'txt');
    }

    public function testEmbeddedScriptInTextIsRejected(): void
    {
        $path = $this->tempFile("name,qty\nwidget,2\nnote,<script>alert(1)</script>\n");

        $this->expectExceptionMessage('This file type is not allowed.');
        $this->validator->assertSafeContent($path, 'csv');
    }

    public function testDetectMimeTypeFallsBackToLowercase(): void
    {
        $path = $this->tempFile(base64_decode(self::PNG_1X1));

        $this->assertSame('image/png', $this->validator->detectMimeType($path));
    }

    public function testGeneratedStoredFilenameIsRandomHex(): void
    {
        $first = $this->validator->generateStoredFilename('pdf');
        $second = $this->validator->generateStoredFilename('pdf');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}\.pdf$/', $first);
        $this->assertNotSame($first, $second);
    }

    public function testInlineImageMimeTypes(): void
    {
        $this->assertTrue($this->validator->isInlineImageMimeType('IMAGE/PNG'));
        $this->assertTrue($this->validator->isInlineImageMimeType('image/webp'));
        $this->assertFalse($this->validator->isInlineImageMimeType('image/svg+xml'));
        $this->assertFalse($this->validator->isInlineImageMimeType('application/pdf'));
        $this->assertFalse($this->validator->isInlineImageMimeType(null));
    }
}
