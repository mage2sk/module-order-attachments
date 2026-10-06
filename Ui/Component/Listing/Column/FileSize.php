<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

class FileSize extends Column
{
    public function prepareDataSource(array $dataSource): array
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                if (isset($item['file_size'])) {
                    $bytes = (int) $item['file_size'];
                    if ($bytes >= 1048576) {
                        $item['file_size'] = round($bytes / 1048576, 2) . ' MB';
                    } elseif ($bytes >= 1024) {
                        $item['file_size'] = round($bytes / 1024, 1) . ' KB';
                    } else {
                        $item['file_size'] = $bytes . ' B';
                    }
                }
            }
        }
        return $dataSource;
    }
}
