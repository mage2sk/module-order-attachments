<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Model\Upload;

use Magento\Framework\Exception\LocalizedException;

class FileValidator
{
    public const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/x-ms-bmp',
    ];

    private const DENIED_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phpt', 'inc',
        'htaccess', 'htpasswd', 'shtml', 'cgi', 'pl', 'py', 'sh', 'asp', 'aspx', 'jsp',
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xht', 'xml', 'xsl', 'js', 'mjs', 'swf', 'exe', 'bat', 'cmd',
    ];

    private const DENIED_MIME_TYPES = [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'text/xml',
        'application/xml',
        'text/javascript',
        'application/javascript',
        'application/x-javascript',
        'text/x-php',
        'application/x-php',
        'application/x-httpd-php',
        'application/x-shockwave-flash',
        'application/x-dosexec',
        'application/x-executable',
        'text/x-shellscript',
    ];

    private const EXPECTED_MIME_TYPES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'pdf' => ['application/pdf'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'doc' => ['application/msword', 'application/cdfv2', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'xls' => ['application/vnd.ms-excel', 'application/cdfv2', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/cdfv2', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
    ];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public function normalizeFilename(string $filename): string
    {
        $filename = str_replace(["\\", '/'], '_', $filename);
        $filename = (string) preg_replace('/[\x00-\x1F\x7F<>"\']/', '', $filename);
        $filename = trim($filename, " .\t");
        if ($filename === '') {
            $filename = 'file';
        }
        if (mb_strlen($filename) > 200) {
            $extension = (string) pathinfo($filename, PATHINFO_EXTENSION);
            $filename = mb_substr($filename, 0, 190) . ($extension !== '' ? '.' . mb_substr($extension, 0, 8) : '');
        }

        return $filename;
    }

    public function getExtension(string $filename): string
    {
        return strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    }

    public function assertAllowedExtension(string $filename, array $allowedExtensions): string
    {
        if (strpos($filename, "\0") !== false) {
            throw new LocalizedException(__('This file type is not allowed.'));
        }
        $extension = $this->getExtension($filename);
        $allowed = array_filter(array_map(static fn($ext) => strtolower(trim((string) $ext)), $allowedExtensions));
        if ($extension === ''
            || !preg_match('/^[a-z0-9]{1,10}$/', $extension)
            || in_array($extension, self::DENIED_EXTENSIONS, true)
            || !in_array($extension, $allowed, true)
        ) {
            throw new LocalizedException(__('This file type is not allowed.'));
        }

        return $extension;
    }

    public function assertSize(int $size, int $maxBytes): void
    {
        if ($size <= 0) {
            throw new LocalizedException(__('The uploaded file is empty.'));
        }
        if ($maxBytes <= 0 || $size > $maxBytes) {
            throw new LocalizedException(
                __('File size exceeds the maximum allowed size of %1 MB.', (int) round($maxBytes / 1048576))
            );
        }
    }

    public function detectMimeType(string $path): string
    {
        $mimeType = '';
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = (string) $finfo->file($path);
        }

        return strtolower($mimeType !== '' ? $mimeType : 'application/octet-stream');
    }

    public function assertSafeContent(string $path, string $extension): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new LocalizedException(__('File upload failed.'));
        }

        $mimeType = $this->detectMimeType($path);
        if (in_array($mimeType, self::DENIED_MIME_TYPES, true)) {
            throw new LocalizedException(__('This file type is not allowed.'));
        }

        if (isset(self::EXPECTED_MIME_TYPES[$extension])
            && !in_array($mimeType, self::EXPECTED_MIME_TYPES[$extension], true)
        ) {
            throw new LocalizedException(__('The file content does not match its extension.'));
        }

        $handle = fopen($path, 'rb');
        $head = $handle ? (string) fread($handle, 4096) : '';
        if ($handle) {
            fclose($handle);
        }
        if (preg_match('/<\?php|<script[\s>]|<html[\s>]|<svg[\s>]|<!doctype\s+html/i', $head)) {
            throw new LocalizedException(__('This file type is not allowed.'));
        }

        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            $imageInfo = getimagesize($path);
            if ($imageInfo === false || !in_array(strtolower((string) ($imageInfo['mime'] ?? '')), self::IMAGE_MIME_TYPES, true)) {
                throw new LocalizedException(__('The file content does not match its extension.'));
            }
        }

        return $mimeType;
    }

    public function generateStoredFilename(string $extension): string
    {
        return bin2hex(random_bytes(20)) . '.' . $extension;
    }

    public function isInlineImageMimeType(?string $mimeType): bool
    {
        return in_array(strtolower((string) $mimeType), self::IMAGE_MIME_TYPES, true);
    }
}
